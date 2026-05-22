<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PayrollAccountMapping;
use App\Models\PayrollSettlement;
use App\Services\Accounting\JournalEntryNumberer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contabiliza el pago de una liquidación de prestaciones sociales:
 *   DR Prestaciones por pagar (cesantías, intereses, prima, vacaciones)
 *   CR Caja / Banco
 */
class PayrollSettlementService
{
    public function __construct(protected JournalEntryNumberer $numberer) {}

    public function pay(PayrollSettlement $settlement, int $cashAccountId, string $paymentDate): PayrollSettlement
    {
        if ($settlement->isPaid()) {
            throw new RuntimeException('La liquidación ya está pagada.');
        }
        if ((float) $settlement->amount <= 0) {
            throw new RuntimeException('La liquidación no tiene valor a pagar.');
        }

        // Componente → casilla contable (prestaciones por pagar / gasto
        // indemnización). Todas se debitan; la caja se acredita por el total.
        $components = array_filter([
            'payable_cesantias' => (float) $settlement->amount_cesantias,
            'payable_interest' => (float) $settlement->amount_interest,
            'payable_prima' => (float) $settlement->amount_prima,
            'payable_vacaciones' => (float) $settlement->amount_vacaciones,
            'expense_severance' => (float) $settlement->amount_indemnizacion,
        ], fn ($amount) => $amount > 0);

        $accounts = PayrollAccountMapping::query()->pluck('account_id', 'slot');
        $missing = array_diff(array_keys($components), $accounts->keys()->all());
        if (! empty($missing)) {
            $names = array_map(fn ($slot) => PayrollAccountSlots::name($slot), $missing);
            throw new RuntimeException(
                'Faltan cuentas por configurar en Nómina → Cuentas de Nómina: '.implode(', ', $names).'.'
            );
        }

        return DB::transaction(function () use ($settlement, $cashAccountId, $paymentDate, $accounts, $components) {
            $company = Company::find($settlement->company_id);
            $number = $this->numberer->next($company, 'CE');
            $employee = $settlement->employee;
            $amount = round((float) $settlement->amount, 2);

            $entry = JournalEntry::create([
                'company_id' => $settlement->company_id,
                'prefix' => 'CE',
                'number' => $number,
                'date' => $paymentDate,
                'type' => 'payment',
                'reference' => 'LIQ-'.$settlement->id,
                'description' => 'Liquidación '.$settlement->typeLabel().' — '.($employee?->fullName() ?? ''),
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'created_by_user_id' => Auth::id(),
                'total_debit' => $amount,
                'total_credit' => $amount,
            ]);

            $line = 1;
            foreach ($components as $slot => $componentAmount) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $accounts[$slot],
                    'third_party_id' => $employee?->third_party_id,
                    'description' => PayrollAccountSlots::name($slot),
                    'debit' => round((float) $componentAmount, 2),
                    'credit' => 0,
                ]);
            }
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line,
                'account_id' => $cashAccountId,
                'third_party_id' => $employee?->third_party_id,
                'description' => 'Pago liquidación de prestaciones',
                'debit' => 0,
                'credit' => $amount,
            ]);

            $settlement->update([
                'status' => PayrollSettlement::STATUS_PAID,
                'payment_date' => $paymentDate,
                'paid_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            return $settlement->fresh();
        });
    }
}
