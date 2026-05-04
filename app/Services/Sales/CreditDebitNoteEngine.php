<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Postea NC/ND y genera asientos contables (signo invertido según type).
 *
 * Nota Crédito (revierte la venta):
 *   DR 4135 Ingresos             (subtotal-dto)
 *   DR 240805 IVA generado       (tax_total)
 *      CR 1305 CxC del cliente   (total)
 *   + Si affects_inventory:
 *     DR 1435 Inventario        (cost_at_return × qty)
 *        CR 6135 COGS           (mismo monto)
 *
 * Nota Débito (aumenta la cuenta del cliente):
 *   DR 1305 CxC                  (total)
 *      CR 4135 Ingresos          (subtotal-dto)
 *      CR 240805 IVA generado    (tax_total)
 */
class CreditDebitNoteEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $numberer,
    ) {}

    public function post(CreditDebitNote $note): CreditDebitNote
    {
        if (! $note->isDraft()) {
            throw new RuntimeException('Solo se puede postear una NC/ND en borrador.');
        }

        $note->load(['lines.product', 'lines.tax', 'customer', 'location', 'saleInvoice']);

        if ($note->lines->isEmpty()) {
            throw new RuntimeException('La nota no tiene líneas.');
        }

        return DB::transaction(function () use ($note) {
            $this->recalculateTotals($note);

            // 1. Si la sede tiene resolución DIAN del tipo correspondiente
            //    (NC=2, ND=3), reservamos consecutivo. Si no, usamos número manual.
            $this->reserveDianNumberIfApplicable($note);

            // 2. Inventario solo en NC con flag affects_inventory (devolución física).
            $totalCogsReversal = 0;
            if ($note->isCredit() && $note->affects_inventory) {
                foreach ($note->lines as $line) {
                    if (! $line->product_id || ! $line->product?->track_inventory) {
                        continue;
                    }

                    // Costo: el snapshoteado en cost_at_return o si no, WAvg actual.
                    $costAtReturn = (float) ($line->cost_at_return ?: 0);

                    $movement = $this->inventory->addMovement(
                        $line->product,
                        $note->location,
                        [
                            'type' => 'return_from_customer',
                            'quantity' => (float) $line->quantity,
                            'unit_cost' => $costAtReturn,
                            'date' => $note->date,
                            'reference_type' => CreditDebitNote::class,
                            'reference_id' => $note->id,
                            'reference_number' => $note->fullNumber(),
                            'third_party_id' => $note->third_party_id,
                            'description' => "Devolución NC {$note->fullNumber()} — {$note->customer->name}",
                        ]
                    );

                    $line->update([
                        'inventory_movement_id' => $movement->id,
                        'cost_at_return' => $movement->unit_cost,
                    ]);

                    $totalCogsReversal += abs((float) $movement->quantity) * (float) $movement->unit_cost;
                }
            }

            // 3. Asiento contable invertido según type.
            $journalEntry = $this->createJournalEntry($note);

            // 4. Reversa COGS si hubo devolución de inventario (NC con affects_inventory).
            if ($totalCogsReversal > 0) {
                $this->createCogsReversalEntry($note, $totalCogsReversal);
            }

            $note->update([
                'status' => CreditDebitNote::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $journalEntry->id,
                'dian_status' => $note->dian_status ?: CreditDebitNote::DIAN_PENDING,
            ]);

            return $note->fresh();
        });
    }

    public function recalculateTotals(CreditDebitNote $note): void
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($note->lines as $line) {
            $subtotal += (float) $line->subtotal;
            $discount += (float) $line->discount_amount;
            $tax += (float) $line->tax_amount;
            $total += (float) $line->total;
        }

        $note->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => $total,
        ]);
    }

    protected function reserveDianNumberIfApplicable(CreditDebitNote $note): void
    {
        // type_document_id en location_resolutions: 2=NC, 3=ND
        $docTypeId = $note->isCredit() ? 2 : 3;
        $assignment = $note->location->activeResolution(documentTypeId: $docTypeId);

        if (! $assignment) {
            return;
        }

        $resolution = $assignment->resolution;
        $next = $assignment->reserveNextNumber();

        if ($next > $resolution->range_to) {
            throw new RuntimeException(sprintf(
                'Consecutivo agotado para la resolución %s (rango %s-%s).',
                $resolution->resolution_number ?: '?',
                number_format($resolution->range_from),
                number_format($resolution->range_to),
            ));
        }

        $note->update([
            'prefix' => $resolution->prefix ?: $note->prefix,
            'number' => $next,
            'dian_resolution_id' => $resolution->id,
            'dian_status' => CreditDebitNote::DIAN_PENDING,
        ]);

        $note->refresh();
        $note->load(['lines.product', 'lines.tax', 'customer', 'location', 'saleInvoice']);
    }

    /**
     * Asiento principal — invertido según type.
     */
    protected function createJournalEntry(CreditDebitNote $note): JournalEntry
    {
        $vatAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '240805')
            ->value('id');

        $defaultIncomeAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '4135')
            ->value('id');

        $receivableAccountId = $note->customer?->default_receivable_account_id
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $note->company_id)
                ->where('code', '1305')
                ->value('id');

        if (! $receivableAccountId) {
            throw new RuntimeException('Falta cuenta por cobrar (1305).');
        }
        if (! $defaultIncomeAccountId) {
            throw new RuntimeException('Falta cuenta de ingresos (4135) en el PUC.');
        }

        $company = Company::find($note->company_id);
        $entryPrefix = $note->isCredit() ? 'NC' : 'ND';
        $number = $this->numberer->next($company, $entryPrefix);

        $entry = JournalEntry::create([
            'company_id' => $note->company_id,
            'prefix' => $entryPrefix,
            'number' => $number,
            'date' => $note->date,
            'type' => $note->isCredit() ? 'credit_note' : 'debit_note',
            'reference' => $note->fullNumber(),
            'third_party_id' => $note->third_party_id,
            'description' => sprintf(
                '%s %s — %s (factura %s)',
                $note->typeLabel(),
                $note->fullNumber(),
                $note->customer->name,
                $note->saleInvoice?->fullNumber() ?? '?',
            ),
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $note->total,
            'total_credit' => $note->total,
        ]);

        $line = 1;
        $isCredit = $note->isCredit();

        if ($isCredit) {
            // NC: revierte ingresos (DR), revierte IVA (DR), revierte CxC (CR)
            // DR ingresos por línea
            foreach ($note->lines as $invLine) {
                $accountId = $invLine->account_id ?? $defaultIncomeAccountId;
                $netAmount = (float) $invLine->subtotal - (float) $invLine->discount_amount;
                if ($netAmount <= 0) continue;

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $accountId,
                    'third_party_id' => $note->third_party_id,
                    'description' => "Reversa línea {$invLine->line_number}: {$invLine->description}",
                    'debit' => $netAmount,
                    'credit' => 0,
                ]);
            }

            // DR IVA generado (revierte)
            if ((float) $note->tax_total > 0 && $vatAccountId) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $vatAccountId,
                    'third_party_id' => $note->third_party_id,
                    'description' => 'Reversa IVA generado',
                    'debit' => $note->tax_total,
                    'credit' => 0,
                ]);
            }

            // CR CxC (cliente debe menos)
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line,
                'account_id' => $receivableAccountId,
                'third_party_id' => $note->third_party_id,
                'description' => "Reversa CxC {$note->customer->name}",
                'debit' => 0,
                'credit' => $note->total,
            ]);
        } else {
            // ND: aumenta CxC (DR), genera más ingreso/IVA (CR)
            // DR CxC
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $receivableAccountId,
                'third_party_id' => $note->third_party_id,
                'description' => "Cargo adicional CxC {$note->customer->name}",
                'debit' => $note->total,
                'credit' => 0,
            ]);

            // CR ingresos por línea
            foreach ($note->lines as $invLine) {
                $accountId = $invLine->account_id ?? $defaultIncomeAccountId;
                $netAmount = (float) $invLine->subtotal - (float) $invLine->discount_amount;
                if ($netAmount <= 0) continue;

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $accountId,
                    'third_party_id' => $note->third_party_id,
                    'description' => "Línea {$invLine->line_number}: {$invLine->description}",
                    'debit' => 0,
                    'credit' => $netAmount,
                ]);
            }

            // CR IVA
            if ((float) $note->tax_total > 0 && $vatAccountId) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line,
                    'account_id' => $vatAccountId,
                    'third_party_id' => $note->third_party_id,
                    'description' => 'IVA generado adicional',
                    'debit' => 0,
                    'credit' => $note->tax_total,
                ]);
            }
        }

        return $entry;
    }

    /**
     * Reversa de COGS por devolución física (solo NC con affects_inventory):
     *   DR 1435 Inventario
     *      CR 6135 COGS
     */
    protected function createCogsReversalEntry(CreditDebitNote $note, float $totalCogs): ?JournalEntry
    {
        $cogsAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '6135')
            ->value('id');

        $inventoryAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '1435')
            ->value('id');

        if (! $cogsAccountId || ! $inventoryAccountId) {
            return null;
        }

        $company = Company::find($note->company_id);
        $number = $this->numberer->next($company, 'NC');

        $entry = JournalEntry::create([
            'company_id' => $note->company_id,
            'prefix' => 'NC',
            'number' => $number,
            'date' => $note->date,
            'type' => 'cogs_reversal',
            'reference' => $note->fullNumber(),
            'third_party_id' => $note->third_party_id,
            'description' => "Reversa costo NC {$note->fullNumber()}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $totalCogs,
            'total_credit' => $totalCogs,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 1,
            'account_id' => $inventoryAccountId,
            'description' => "Reingreso inventario {$note->fullNumber()}",
            'debit' => $totalCogs,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 2,
            'account_id' => $cogsAccountId,
            'description' => "Reversa COGS {$note->fullNumber()}",
            'debit' => 0,
            'credit' => $totalCogs,
        ]);

        return $entry;
    }
}
