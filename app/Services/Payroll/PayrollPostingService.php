<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PayrollAccountMapping;
use App\Models\PayrollPeriod;
use App\Services\Accounting\JournalEntryNumberer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contabiliza un período de nómina: arma el asiento contable agregando
 * los desprendibles del período según el mapeo de cuentas configurado.
 *
 *   DR Gastos (salarios, auxilio, novedades, aportes patronales, provisiones)
 *   CR Pasivos (neto por pagar, aportes por pagar, deducciones, provisiones)
 */
class PayrollPostingService
{
    public function __construct(protected JournalEntryNumberer $numberer) {}

    public function post(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->isPosted()) {
            throw new RuntimeException('La nómina ya está contabilizada.');
        }
        if (! $period->isLiquidated()) {
            throw new RuntimeException('Primero debes liquidar la nómina.');
        }

        $slips = $period->slips()->with('lines')->get();
        if ($slips->isEmpty()) {
            throw new RuntimeException('El período no tiene desprendibles. Liquidá la nómina primero.');
        }

        // Scope explicito por company_id del period. Si este servicio corre
        // desde una queue/artisan sin CurrentCompany hidratado, el scope
        // global esta inerte y podria pluckear mappings de otras empresas.
        $accounts = PayrollAccountMapping::query()
            ->where('company_id', $period->company_id)
            ->pluck('account_id', 'slot');
        // 'expense_severance' solo lo usa la liquidación de prestaciones,
        // no la nómina mensual.
        $required = array_diff(PayrollAccountSlots::codes(), ['expense_severance']);
        $missing = array_diff($required, $accounts->keys()->all());
        if (! empty($missing)) {
            $names = array_map(fn ($slot) => PayrollAccountSlots::name($slot), $missing);
            throw new RuntimeException(
                'Faltan cuentas por configurar en Nómina → Cuentas de Nómina: '.implode(', ', $names).'.'
            );
        }

        $totals = $this->aggregate($slips);

        return DB::transaction(function () use ($period, $accounts, $totals) {
            $company = Company::find($period->company_id);
            $number = $this->numberer->next($company, 'NOM');
            $date = ($period->payment_date ?? $period->end_date)?->toDateString() ?? now()->toDateString();

            $debits = [
                'expense_salary' => $totals['salary'],
                'expense_transport' => $totals['transport'],
                'expense_overtime' => $totals['overtime'],
                'expense_employer_contributions' => $totals['employer'],
                'expense_provisions' => $totals['provisions'],
            ];
            $credits = [
                'payable_net_salary' => $totals['net'],
                'payable_health' => $totals['health'],
                'payable_pension' => $totals['pension'],
                'payable_arl' => $totals['arl'],
                'payable_parafiscal' => $totals['parafiscal'],
                'payable_withholding' => $totals['withholding'],
                'payable_other_deductions' => $totals['other_deductions'],
                'payable_cesantias' => $totals['cesantias'],
                'payable_interest' => $totals['interest'],
                'payable_prima' => $totals['prima'],
                'payable_vacaciones' => $totals['vacaciones'],
            ];

            $entry = JournalEntry::create([
                'company_id' => $period->company_id,
                'prefix' => 'NOM',
                'number' => $number,
                'date' => $date,
                'type' => 'payroll',
                'reference' => $period->name,
                'description' => 'Liquidación de nómina — '.$period->name,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'created_by_user_id' => Auth::id(),
                'total_debit' => round(array_sum($debits), 2),
                'total_credit' => round(array_sum($credits), 2),
            ]);

            $line = 1;
            foreach ($debits as $slot => $amount) {
                if (round((float) $amount, 2) <= 0) {
                    continue;
                }
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $accounts[$slot],
                    'description' => PayrollAccountSlots::name($slot),
                    'debit' => round((float) $amount, 2),
                    'credit' => 0,
                ]);
            }
            foreach ($credits as $slot => $amount) {
                if (round((float) $amount, 2) <= 0) {
                    continue;
                }
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $accounts[$slot],
                    'description' => PayrollAccountSlots::name($slot),
                    'debit' => 0,
                    'credit' => round((float) $amount, 2),
                ]);
            }

            $period->update([
                'status' => PayrollPeriod::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
            ]);

            return $period->fresh();
        });
    }

    /**
     * Suma los desprendibles del período en los grupos contables del asiento.
     */
    protected function aggregate($slips): array
    {
        $totals = array_fill_keys([
            'salary', 'transport', 'overtime', 'employer', 'provisions', 'net',
            'health', 'pension', 'arl', 'parafiscal', 'withholding', 'other_deductions',
            'cesantias', 'interest', 'prima', 'vacaciones',
        ], 0.0);

        foreach ($slips as $slip) {
            $totals['salary'] += (float) $slip->salary_earned;
            $totals['transport'] += (float) $slip->transport_allowance;
            $totals['overtime'] += (float) $slip->total_earnings
                - (float) $slip->salary_earned - (float) $slip->transport_allowance;
            $totals['employer'] += (float) $slip->employer_health + (float) $slip->employer_pension
                + (float) $slip->employer_arl + (float) $slip->employer_caja
                + (float) $slip->employer_sena + (float) $slip->employer_icbf;
            $totals['provisions'] += (float) $slip->prov_cesantias + (float) $slip->prov_interest
                + (float) $slip->prov_prima + (float) $slip->prov_vacaciones;
            $totals['net'] += (float) $slip->net_pay;
            $totals['health'] += (float) $slip->employee_health + (float) $slip->employer_health;
            $totals['pension'] += (float) $slip->employee_pension + (float) $slip->solidarity_fund
                + (float) $slip->employer_pension;
            $totals['arl'] += (float) $slip->employer_arl;
            $totals['parafiscal'] += (float) $slip->employer_caja
                + (float) $slip->employer_sena + (float) $slip->employer_icbf;

            $retencion = (float) $slip->lines
                ->where('type', 'deduction')
                ->where('concept_code', 'retencion_fuente')
                ->sum('amount');
            $totals['withholding'] += $retencion;

            $legalDeductions = (float) $slip->employee_health
                + (float) $slip->employee_pension + (float) $slip->solidarity_fund;
            $totals['other_deductions'] += (float) $slip->total_deductions - $legalDeductions - $retencion;

            $totals['cesantias'] += (float) $slip->prov_cesantias;
            $totals['interest'] += (float) $slip->prov_interest;
            $totals['prima'] += (float) $slip->prov_prima;
            $totals['vacaciones'] += (float) $slip->prov_vacaciones;
        }

        return array_map(fn ($v) => round($v, 2), $totals);
    }
}
