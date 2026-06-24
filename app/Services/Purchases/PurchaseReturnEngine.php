<?php

namespace App\Services\Purchases;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseReturn;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de devoluciones a proveedor. Espejo de PurchaseInvoiceEngine:
 *  - movimiento de inventario return_to_supplier por línea
 *  - asiento contable: DR CxP / CR Inventario(s) + IVA descontable
 *  - si referencia una purchase_invoice, NO modifica el saldo de la
 *    factura origen — el contador suele manejar esto como nota débito
 *    independiente. Si se quiere reducir el saldo, se cancela el pago
 *    de la diferencia manualmente.
 */
class PurchaseReturnEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $journalNumberer,
    ) {}

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        if (! $return->isDraft()) {
            throw new RuntimeException('Solo se pueden contabilizar devoluciones en borrador.');
        }

        $return->loadMissing(['lines.product', 'lines.tax', 'supplier', 'location']);

        if ($return->lines->isEmpty()) {
            throw new RuntimeException('La devolución no tiene líneas.');
        }

        return DB::transaction(function () use ($return) {
            $this->recalculateTotals($return);

            $invByAccount = []; // {account_id => subtotal} para JE
            $totalTax = 0.0;
            $totalSubtotal = 0.0;

            foreach ($return->lines as $line) {
                $product = $line->product;
                if (! $product) {
                    throw new RuntimeException("Línea {$line->line_number}: producto no encontrado.");
                }
                if (! $product->track_inventory) {
                    throw new RuntimeException("Producto {$product->code} no controla inventario.");
                }

                $qty = (float) $line->quantity;
                if ($qty <= 0) {
                    throw new RuntimeException("Línea {$line->line_number}: cantidad debe ser mayor a 0.");
                }

                // Para devolver al proveedor, el costo debe ser el promedio
                // actual en la sede (lo que de hecho saldrá del inventario).
                // Si el usuario digitó otro unit_cost, lo respetamos para el
                // JE (que cuadra con CxP), pero el movement usa el promedio.
                $movement = $this->inventory->addMovement(
                    $product,
                    $return->location,
                    [
                        'type' => 'return_to_supplier',
                        'quantity' => $qty,
                        'unit_cost' => (float) $line->unit_cost,
                        'date' => $return->date,
                        'reference_type' => PurchaseReturn::class,
                        'reference_id' => $return->id,
                        'reference_number' => $return->fullNumber(),
                        'third_party_id' => $return->third_party_id,
                        'description' => "Devolución {$return->fullNumber()} — {$return->supplier->name}",
                    ],
                );

                $line->update(['inventory_movement_id' => $movement->id]);

                $invAccountId = $product->effectiveInventoryAccountId()
                    ?? $this->defaultInventoryAccountId($return->company_id);
                if (! $invAccountId) {
                    throw new RuntimeException(
                        "Producto {$product->code} sin cuenta de inventario. Configúrala en el producto o crea la cuenta 1435."
                    );
                }
                $invByAccount[$invAccountId] = ($invByAccount[$invAccountId] ?? 0) + (float) $line->subtotal;
                $totalSubtotal += (float) $line->subtotal;
                $totalTax += (float) $line->tax_amount;
            }

            $entry = $this->createJournalEntry($return, $invByAccount, $totalSubtotal, $totalTax);

            $return->update([
                'status' => PurchaseReturn::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
            ]);

            return $return->fresh(['lines']);
        });
    }

    public function recalculateTotals(PurchaseReturn $return): void
    {
        $subtotal = 0;
        $tax = 0;
        $total = 0;

        foreach ($return->lines as $line) {
            $subtotal += (float) $line->subtotal;
            $tax += (float) $line->tax_amount;
            $total += (float) $line->total;
        }

        $return->update([
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total' => $total,
        ]);
    }

    protected function createJournalEntry(PurchaseReturn $return, array $invByAccount, float $subtotal, float $tax): JournalEntry
    {
        $vatAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $return->company_id)
            ->where('code', '240810')
            ->value('id');

        $payableAccountId = $return->supplier?->default_payable_account_id
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $return->company_id)
                ->where('code', '220505')
                ->value('id');

        if (! $payableAccountId) {
            throw new RuntimeException('Falta cuenta por pagar (220505 o configurar en el proveedor).');
        }

        $company = Company::find($return->company_id);
        $number = $this->journalNumberer->next($company, 'AS');

        $total = round($subtotal + $tax, 2);

        $entry = JournalEntry::create([
            'company_id' => $return->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $return->date,
            'type' => 'purchase',  // devolución contable es una "compra negativa"
            'reference' => $return->fullNumber(),
            'third_party_id' => $return->third_party_id,
            'description' => "Devolución a proveedor {$return->fullNumber()} — {$return->supplier->name}",
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => $total,
            'total_credit' => $total,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
        ]);

        $lineNum = 1;

        // DR CxP proveedor (lo que ya no le debemos)
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $lineNum++,
            'account_id' => $payableAccountId,
            'third_party_id' => $return->third_party_id,
            'debit' => $total,
            'credit' => 0,
            'description' => "Devolución CxP {$return->supplier->name}",
        ]);

        // CR inventario (por cuenta)
        foreach ($invByAccount as $accountId => $amount) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $accountId,
                'third_party_id' => $return->third_party_id,
                'debit' => 0,
                'credit' => round($amount, 2),
                'description' => 'Salida inventario por devolución',
            ]);
        }

        // CR IVA descontable (reversa)
        if ($tax > 0) {
            if (! $vatAccountId) {
                throw new RuntimeException('Falta cuenta IVA descontable (240810) en el PUC.');
            }
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $vatAccountId,
                'third_party_id' => $return->third_party_id,
                'debit' => 0,
                'credit' => round($tax, 2),
                'description' => 'Reversa IVA descontable',
            ]);
        }

        return $entry;
    }

    /**
     * Cancela una devolución posteada: reverso de movimientos + asiento espejo.
     */
    public function cancel(PurchaseReturn $return, ?string $reason = null): PurchaseReturn
    {
        if (! $return->isPosted()) {
            throw new RuntimeException('Solo se pueden anular devoluciones contabilizadas.');
        }

        $return->loadMissing(['lines.product', 'location', 'journalEntry.lines']);

        return DB::transaction(function () use ($return, $reason) {
            // Reverso movimientos: la devolución sacó stock, ahora regresa
            // entrada de ajuste para reponer.
            foreach ($return->lines as $line) {
                if (! $line->product || ! $line->inventory_movement_id) continue;

                $this->inventory->addMovement(
                    $line->product,
                    $return->location,
                    [
                        'type' => 'adjustment_in',
                        'quantity' => (float) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                        'date' => now()->toDateString(),
                        'reference_type' => PurchaseReturn::class,
                        'reference_id' => $return->id,
                        'reference_number' => 'REV-'.$return->fullNumber(),
                        'description' => 'Reverso devolución '.$return->fullNumber().($reason ? ' — '.$reason : ''),
                    ],
                );
            }

            // Reverso del asiento
            if ($return->journal_entry_id && $return->journalEntry) {
                $original = $return->journalEntry;
                $company = Company::find($return->company_id);
                $number = $this->journalNumberer->next($company, 'AS');

                $reverso = JournalEntry::create([
                    'company_id' => $return->company_id,
                    'prefix' => 'AS',
                    'number' => $number,
                    'date' => now()->toDateString(),
                    'type' => 'reversal',
                    'reference' => 'REV-'.$return->fullNumber(),
                    'description' => 'Reverso devolución '.$return->fullNumber().($reason ? ' — '.$reason : ''),
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

            $return->update([
                'status' => PurchaseReturn::STATUS_CANCELLED,
                'notes' => trim(($return->notes ?? '')."\nAnulada: ".($reason ?? 'sin motivo')),
            ]);

            return $return->fresh();
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
