<?php

namespace App\Services\Inventory;

use App\Models\Account;
use App\Models\Company;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalEntryNumberer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de ajustes de inventario. Al postear crea un movimiento de
 * inventario por cada línea (adjustment_in / adjustment_out) y un único
 * JournalEntry que cuadra cuenta de inventario contra la counterpart
 * elegida (sobrante/recuperación o pérdida/gasto).
 */
class InventoryAdjustmentEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $journalNumberer,
    ) {}

    public function post(InventoryAdjustment $adj): InventoryAdjustment
    {
        if (! $adj->isDraft()) {
            throw new RuntimeException('Solo se pueden contabilizar ajustes en borrador.');
        }

        $adj->loadMissing(['lines.product', 'location', 'counterpartAccount']);

        if ($adj->lines->isEmpty()) {
            throw new RuntimeException('El ajuste no tiene líneas.');
        }

        return DB::transaction(function () use ($adj) {
            $totalCost = 0.0;
            $movementsByAccount = []; // {account_id => total_amount} para JE

            foreach ($adj->lines as $line) {
                $product = $line->product;
                if (! $product) {
                    throw new RuntimeException("Línea {$line->line_number}: producto no encontrado.");
                }
                if (! $product->track_inventory) {
                    throw new RuntimeException(
                        "Producto {$product->code} no controla inventario — no se puede ajustar."
                    );
                }

                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    throw new RuntimeException("Línea {$line->line_number}: cantidad debe ser mayor a 0.");
                }

                // Para salidas, el costo lo determina el promedio actual.
                // Para entradas, el costo lo digita el usuario en la línea.
                $unitCost = $adj->isEntry()
                    ? (float) $line->unit_cost
                    : $this->inventory->currentAvgCost($product->id, $adj->location_id);

                if ($adj->isEntry() && $unitCost <= 0) {
                    throw new RuntimeException(
                        "Línea {$line->line_number}: las entradas deben tener costo unitario > 0."
                    );
                }

                $movement = $this->inventory->addMovement(
                    $product,
                    $adj->location,
                    [
                        'type' => $adj->isEntry() ? 'adjustment_in' : 'adjustment_out',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'date' => $adj->date,
                        'reference_type' => InventoryAdjustment::class,
                        'reference_id' => $adj->id,
                        'reference_number' => $adj->fullNumber(),
                        'description' => "Ajuste {$adj->fullNumber()} — ".(InventoryAdjustment::REASONS[$adj->reason_code] ?? $adj->reason_code),
                    ],
                );

                $line->update([
                    'unit_cost' => $unitCost,
                    'inventory_movement_id' => $movement->id,
                ]);

                $lineCost = round($qty * $unitCost, 2);
                $totalCost += $lineCost;

                // Cuenta de inventario por producto (default a configurable)
                $invAccountId = $product->inventory_account_id ?? $this->defaultInventoryAccountId($adj->company_id);
                if (! $invAccountId) {
                    throw new RuntimeException(
                        "No se encontró la cuenta de inventario del producto {$product->code}. Configúrala en el producto o en el plan de cuentas (1435)."
                    );
                }
                $movementsByAccount[$invAccountId] = ($movementsByAccount[$invAccountId] ?? 0) + $lineCost;
            }

            if ($totalCost <= 0) {
                throw new RuntimeException('El total del ajuste es cero — nada que contabilizar.');
            }

            $entry = $this->createJournalEntry($adj, $movementsByAccount, $totalCost);

            $adj->update([
                'status' => InventoryAdjustment::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
            ]);

            return $adj->fresh(['lines']);
        });
    }

    protected function createJournalEntry(InventoryAdjustment $adj, array $invByAccount, float $totalCost): JournalEntry
    {
        $company = Company::find($adj->company_id);
        $number = $this->journalNumberer->next($company, 'AS');

        $entry = JournalEntry::create([
            'company_id' => $adj->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $adj->date,
            'type' => 'adjustment',
            'reference' => $adj->fullNumber(),
            'description' => 'Ajuste de inventario '.$adj->fullNumber().' — '.(InventoryAdjustment::REASONS[$adj->reason_code] ?? ''),
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => round($totalCost, 2),
            'total_credit' => round($totalCost, 2),
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
        ]);

        $lineNum = 1;

        if ($adj->isEntry()) {
            // DR inventario (una línea por cuenta de inventario distinta)
            foreach ($invByAccount as $accountId => $amount) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $lineNum++,
                    'account_id' => $accountId,
                    'debit' => round($amount, 2),
                    'credit' => 0,
                    'description' => 'Entrada por ajuste',
                ]);
            }
            // CR counterpart (recuperación / sobrante)
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $adj->counterpart_account_id,
                'debit' => 0,
                'credit' => round($totalCost, 2),
                'description' => 'Contraparte sobrante',
            ]);
        } else {
            // DR counterpart (pérdida / gasto)
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $adj->counterpart_account_id,
                'debit' => round($totalCost, 2),
                'credit' => 0,
                'description' => 'Pérdida por ajuste',
            ]);
            // CR inventario (una línea por cuenta de inventario distinta)
            foreach ($invByAccount as $accountId => $amount) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $lineNum++,
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => round($amount, 2),
                    'description' => 'Salida por ajuste',
                ]);
            }
        }

        return $entry;
    }

    /**
     * Cancela un ajuste posteado: movimiento de inventario espejo +
     * asiento contable inverso.
     */
    public function cancel(InventoryAdjustment $adj, ?string $reason = null): InventoryAdjustment
    {
        if (! $adj->isPosted()) {
            throw new RuntimeException('Solo se pueden anular ajustes contabilizados.');
        }

        $adj->loadMissing(['lines.product', 'location', 'journalEntry.lines']);

        return DB::transaction(function () use ($adj, $reason) {
            // Reverso de movimientos de inventario
            foreach ($adj->lines as $line) {
                if (! $line->product || ! $line->inventory_movement_id) continue;

                $this->inventory->addMovement(
                    $line->product,
                    $adj->location,
                    [
                        'type' => $adj->isEntry() ? 'adjustment_out' : 'adjustment_in',
                        'quantity' => (float) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                        'date' => now()->toDateString(),
                        'reference_type' => InventoryAdjustment::class,
                        'reference_id' => $adj->id,
                        'reference_number' => 'REV-'.$adj->fullNumber(),
                        'description' => 'Reverso ajuste '.$adj->fullNumber().($reason ? ' — '.$reason : ''),
                    ],
                );
            }

            // Reverso del asiento contable
            if ($adj->journal_entry_id && $adj->journalEntry) {
                $original = $adj->journalEntry;
                $company = Company::find($adj->company_id);
                $number = $this->journalNumberer->next($company, 'AS');

                $reverso = JournalEntry::create([
                    'company_id' => $adj->company_id,
                    'prefix' => 'AS',
                    'number' => $number,
                    'date' => now()->toDateString(),
                    'type' => 'reversal',
                    'reference' => 'REV-'.$adj->fullNumber(),
                    'description' => 'Reverso ajuste '.$adj->fullNumber().($reason ? ' — '.$reason : ''),
                    'status' => JournalEntry::STATUS_POSTED,
                    'total_debit' => (float) $original->total_credit,
                    'total_credit' => (float) $original->total_debit,
                    'posted_at' => now(),
                    'posted_by_user_id' => Auth::id(),
                    'created_by_user_id' => Auth::id(),
                    'reversed_entry_id' => $original->id,
                ]);

                $lineNum = 1;
                foreach ($original->lines as $line) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $reverso->id,
                        'line_number' => $lineNum++,
                        'account_id' => $line->account_id,
                        'third_party_id' => $line->third_party_id,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                        'description' => 'Reverso: '.$line->description,
                    ]);
                }
            }

            $adj->update([
                'status' => InventoryAdjustment::STATUS_CANCELLED,
                'notes' => trim(($adj->notes ?? '')."\nAnulado: ".($reason ?? 'sin motivo')),
            ]);

            return $adj->fresh();
        });
    }

    protected function defaultInventoryAccountId(int $companyId): ?int
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', '1435')
            ->value('id');
    }
}
