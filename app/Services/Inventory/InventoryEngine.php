<?php

namespace App\Services\Inventory;

use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Location;
use App\Models\Product;
use App\Services\Accounting\JournalEntryNumberer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Motor de inventario: añade movimientos y mantiene saldos por (producto, sede).
 *
 * Métodos de valoración soportados:
 *   - weighted_average (Promedio Ponderado): IMPLEMENTADO
 *   - fifo / lifo: scaffolded — se calculan como WAvg por ahora; cuando
 *     implementemos lots se cambiará el cálculo de costo en salidas.
 *
 * Cada movimiento puede generar JournalEntry automático si se proveen las
 * cuentas correctas (debit/credit). Para cargas iniciales (opening) se
 * usa generalmente DR Inventario | CR Utilidades acumuladas (3705).
 */
class InventoryEngine
{
    /**
     * Añade un movimiento de inventario.
     *
     * @param  array  $opts  ['type', 'quantity' (siempre positivo), 'unit_cost',
     *                        'date', 'reference_type', 'reference_id', 'reference_number',
     *                        'third_party_id', 'description']
     * @return InventoryMovement
     */
    public function addMovement(Product $product, Location $location, array $opts): InventoryMovement
    {
        $type = $opts['type'];
        $quantity = (float) abs($opts['quantity'] ?? 0);
        $unitCost = (float) ($opts['unit_cost'] ?? 0);
        $date = isset($opts['date']) ? Carbon::parse($opts['date']) : now();

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad del movimiento debe ser mayor a 0.');
        }

        if (! in_array($type, array_keys(InventoryMovement::TYPES), true)) {
            throw new \InvalidArgumentException("Tipo de movimiento inválido: {$type}");
        }

        $isEntry = in_array($type, InventoryMovement::ENTRY_TYPES, true);
        $signedQty = $isEntry ? $quantity : -$quantity;

        return DB::transaction(function () use ($product, $location, $opts, $type, $quantity, $signedQty, $unitCost, $date, $isEntry) {
            $last = $this->lastMovement($product->id, $location->id);
            $prevBalance = $last ? (float) $last->balance_quantity_after : 0.0;
            $prevAvgCost = $last ? (float) $last->balance_cost_after : 0.0;
            $prevValue = $last ? (float) $last->balance_value_after : 0.0;

            $company = Company::find($product->company_id);
            $method = $company?->inventory_method ?? 'weighted_average';

            // Costo a usar y nuevo promedio según método
            if ($isEntry) {
                $newBalance = $prevBalance + $quantity;
                $newValue = $prevValue + ($quantity * $unitCost);
                $newAvgCost = $newBalance > 0 ? $newValue / $newBalance : 0;
                $movementUnitCost = $unitCost;
            } else {
                // Salida: el costo unitario depende del método
                $newBalance = $prevBalance - $quantity;
                if ($newBalance < 0) {
                    throw new \RuntimeException(sprintf(
                        'Stock insuficiente en %s para %s. Saldo actual: %s, intento de salida: %s.',
                        $location->fullName(),
                        $product->fullName(),
                        number_format($prevBalance, 4),
                        number_format($quantity, 4),
                    ));
                }

                // WAvg / FIFO / LIFO — por ahora todos usan WAvg (FIFO/LIFO requieren lot tracking, scaffolded para futuro)
                $movementUnitCost = $prevAvgCost;
                $newValue = $prevValue - ($quantity * $movementUnitCost);
                $newAvgCost = $newBalance > 0 ? $newValue / $newBalance : 0;
            }

            return InventoryMovement::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'date' => $date,
                'type' => $type,
                'quantity' => $signedQty,
                'unit_cost' => $movementUnitCost,
                'total_cost' => abs($signedQty) * $movementUnitCost,
                'balance_quantity_after' => $newBalance,
                'balance_cost_after' => $newAvgCost,
                'balance_value_after' => $newValue,
                'remaining_quantity' => $isEntry ? $quantity : null,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'reference_number' => $opts['reference_number'] ?? null,
                'third_party_id' => $opts['third_party_id'] ?? null,
                'journal_entry_id' => $opts['journal_entry_id'] ?? null,
                'created_by_user_id' => $opts['created_by_user_id'] ?? Auth::id(),
                'description' => $opts['description'] ?? null,
            ]);
        });
    }

    public function lastMovement(int $productId, int $locationId): ?InventoryMovement
    {
        // withoutGlobalScopes() se usa a proposito para evitar depender del
        // singleton CurrentCompany en contextos donde no esta hidratado
        // (queue, artisan, closures Filament). Sin embargo, hay que scopear
        // explicitamente porque los args son ints crudos y podrian venir de
        // input del cliente (POS, forms Livewire). Un product/location
        // crafteado no debe leak el saldo o costo promedio de otra empresa.
        $companyId = app(\App\Support\CurrentCompany::class)->id()
            ?? auth()->user()?->company_id;

        return InventoryMovement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    public function currentStock(int $productId, int $locationId): float
    {
        return (float) ($this->lastMovement($productId, $locationId)?->balance_quantity_after ?? 0);
    }

    public function currentAvgCost(int $productId, int $locationId): float
    {
        return (float) ($this->lastMovement($productId, $locationId)?->balance_cost_after ?? 0);
    }

    /**
     * Genera un JournalEntry de "carga inicial" para una lista de cargas
     * de inventario [{location_id, quantity, unit_cost}].
     *
     * DR: cuenta inventario del producto (o 1435 si no se configuró)
     * CR: cuenta de saldos iniciales (3705 Utilidades acumuladas) — debe existir.
     *
     * Retorna el JournalEntry creado, o null si no hay líneas con valor o falta
     * la cuenta contraparte.
     */
    public function createOpeningJournalEntry(Product $product, array $entries): ?JournalEntry
    {
        $totalValue = 0;
        $debitLines = [];

        foreach ($entries as $e) {
            $qty = (float) ($e['quantity'] ?? 0);
            $cost = (float) ($e['unit_cost'] ?? 0);
            $value = $qty * $cost;

            if ($value <= 0) {
                continue;
            }

            $location = Location::find($e['location_id']);
            $debitLines[] = [
                'value' => $value,
                'description' => sprintf(
                    'Saldo inicial %s — %s (%s × $%s)',
                    $product->fullName(),
                    $location?->fullName() ?? '?',
                    number_format($qty, 2),
                    number_format($cost, 2),
                ),
            ];
            $totalValue += $value;
        }

        if ($totalValue <= 0 || empty($debitLines)) {
            return null;
        }

        $inventoryAccountId = $product->effectiveInventoryAccountId() ?? Account::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('code', '1435')
            ->value('id');

        $counterAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('code', '3705')
            ->value('id');

        if (! $inventoryAccountId || ! $counterAccountId) {
            // Sin cuentas configuradas, no podemos generar el asiento. Saltar silenciosamente.
            return null;
        }

        return DB::transaction(function () use ($product, $debitLines, $totalValue, $inventoryAccountId, $counterAccountId) {
            $number = app(JournalEntryNumberer::class)->next(
                Company::find($product->company_id),
                'AS',
            );

            $entry = JournalEntry::create([
                'company_id' => $product->company_id,
                'prefix' => 'AS',
                'number' => $number,
                'date' => now(),
                'type' => 'opening',
                'reference' => 'Carga inicial '.$product->code,
                'description' => "Saldo inicial de inventario para producto {$product->code} — {$product->name}",
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'created_by_user_id' => Auth::id(),
                'total_debit' => $totalValue,
                'total_credit' => $totalValue,
            ]);

            $line = 1;
            foreach ($debitLines as $dl) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $inventoryAccountId,
                    'description' => $dl['description'],
                    'debit' => $dl['value'],
                    'credit' => 0,
                ]);
            }

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line,
                'account_id' => $counterAccountId,
                'description' => "Contrapartida saldo inicial {$product->code}",
                'debit' => 0,
                'credit' => $totalValue,
            ]);

            return $entry;
        });
    }
}
