<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalEntryNumberer;
use App\Support\CashSessionGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Postea un Expense: crea el asiento contable y deja status=posted.
 * El gasto es siempre al contado:
 *   DR  expense_account_id    (subtotal + tax_amount si no descontable)
 *   DR  IVA descontable (si aplica)
 *   CR  payment_account_id    (total)
 *
 * No registra en payments porque el gasto en sí ES el egreso. Si se
 * descontabiliza/cancela, se reversa el asiento.
 */
class ExpenseEngine
{
    public function __construct(
        protected JournalEntryNumberer $journalNumberer,
    ) {}

    public function post(Expense $expense): Expense
    {
        if ($expense->isPosted()) {
            throw new RuntimeException('El gasto ya está contabilizado.');
        }
        if ($expense->isCancelled()) {
            throw new RuntimeException('No se puede contabilizar un gasto anulado.');
        }
        if ((float) $expense->total <= 0) {
            throw new RuntimeException('El gasto debe tener un total mayor a cero.');
        }

        // El egreso ocurre AHORA, no cuando se creó el draft. Re-bindeamos
        // la sesión actual para que el cuadre del turno que efectivamente
        // postea el gasto refleje el egreso.
        $session = CashSessionGate::requireOpenSession();

        return DB::transaction(function () use ($expense, $session) {
            $this->recalculateTotals($expense);

            $entry = $this->createJournalEntry($expense);

            $expense->update([
                'status' => Expense::STATUS_POSTED,
                'cash_register_session_id' => $session->id,
                'journal_entry_id' => $entry->id,
                'posted_by_user_id' => Auth::id(),
                'posted_at' => now(),
            ]);

            return $expense->fresh();
        });
    }

    public function recalculateTotals(Expense $expense): void
    {
        $subtotal = (float) $expense->subtotal;
        $taxAmount = 0.0;

        if ($expense->tax_id && $expense->tax) {
            $rate = (float) $expense->tax->rate;
            $taxAmount = round($subtotal * $rate / 100, 2);
        }

        $expense->update([
            'tax_rate' => $expense->tax?->rate ?? 0,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
        ]);
    }

    protected function createJournalEntry(Expense $expense): JournalEntry
    {
        $company = $expense->location->company;
        $number = $this->journalNumberer->next($company, 'GS');

        $subtotal = (float) $expense->subtotal;
        $taxAmount = (float) $expense->tax_amount;
        $total = (float) $expense->total;

        $entry = JournalEntry::create([
            'company_id' => $expense->company_id,
            'prefix' => 'GS',
            'number' => $number,
            'date' => $expense->date,
            'type' => 'payment',
            'reference' => $expense->fullNumber(),
            'third_party_id' => $expense->third_party_id,
            'description' => 'Gasto '.$expense->fullNumber().' — '.$expense->concept,
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => round($subtotal + $taxAmount, 2),
            'total_credit' => round($total, 2),
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
        ]);

        $lineNum = 1;

        // DR cuenta de gasto por el subtotal — propaga el centro de costo
        // del gasto a la línea de imputación para reportes por CC.
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $lineNum++,
            'account_id' => $expense->expense_account_id,
            'third_party_id' => $expense->third_party_id,
            'cost_center_id' => $expense->cost_center_id,
            'debit' => $subtotal,
            'credit' => 0,
            'description' => $expense->concept,
        ]);

        // DR IVA del gasto. Por simplicidad MVP va a la misma cuenta de gasto
        // (no descontable). Cuando se modele la cuenta 2408 desde DIAN, separar.
        if ($taxAmount > 0) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $expense->expense_account_id,
                'third_party_id' => $expense->third_party_id,
                'cost_center_id' => $expense->cost_center_id,
                'debit' => $taxAmount,
                'credit' => 0,
                'description' => 'IVA gasto '.$expense->fullNumber(),
            ]);
        }

        // CR cuenta de pago (caja/banco) por el total
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $lineNum++,
            'account_id' => $expense->payment_account_id,
            'third_party_id' => $expense->third_party_id,
            'debit' => 0,
            'credit' => $total,
            'description' => 'Pago gasto '.$expense->fullNumber(),
        ]);

        return $entry;
    }

    /**
     * Cancela un gasto posteado: reversa el asiento creando uno espejo.
     */
    public function cancel(Expense $expense, ?string $reason = null): Expense
    {
        if (! $expense->isPosted()) {
            throw new RuntimeException('Solo se pueden anular gastos contabilizados.');
        }

        return DB::transaction(function () use ($expense, $reason) {
            // Asiento de reverso
            if ($expense->journal_entry_id) {
                $original = $expense->journalEntry;
                $company = $expense->location->company;
                $number = $this->journalNumberer->next($company, 'GS');

                $reverso = JournalEntry::create([
                    'company_id' => $expense->company_id,
                    'prefix' => 'GS',
                    'number' => $number,
                    'date' => now()->toDateString(),
                    'description' => 'Reverso gasto '.$expense->fullNumber().($reason ? ' — '.$reason : ''),
                    'source_type' => Expense::class,
                    'source_id' => $expense->id,
                    'created_by_user_id' => Auth::id(),
                ]);

                $lineNum = 1;
                foreach ($original->lines as $line) {
                    JournalEntryLine::create([
                        'company_id' => $expense->company_id,
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

            $expense->update([
                'status' => Expense::STATUS_CANCELLED,
                'notes' => trim(($expense->notes ?? '')."\nAnulado: ".($reason ?? 'sin motivo')),
            ]);

            return $expense->fresh();
        });
    }
}
