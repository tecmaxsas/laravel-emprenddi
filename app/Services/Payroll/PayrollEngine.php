<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollNovelty;
use App\Models\PayrollParameter;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Models\PayrollSlipLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de liquidación de nómina.
 *
 * Liquida la nómina de cada empleado activo con contrato vigente:
 * salario, auxilio de transporte, novedades (devengados y deducciones),
 * deducciones de ley (salud, pensión, fondo de solidaridad), aportes del
 * empleador y provisiones de prestaciones sociales.
 */
class PayrollEngine
{
    public const RATE_HEALTH_EMP = 0.04;
    public const RATE_PENSION_EMP = 0.04;
    public const RATE_HEALTH_ER = 0.085;
    public const RATE_PENSION_ER = 0.12;
    public const RATE_SENA = 0.02;
    public const RATE_ICBF = 0.03;
    public const RATE_CAJA = 0.04;
    public const RATE_SOLIDARITY = 0.01;
    public const RATE_CESANTIAS = 0.0833;
    public const RATE_INTEREST = 0.01;
    public const RATE_PRIMA = 0.0833;
    public const RATE_VACACIONES = 0.0417;

    public const SOLIDARITY_THRESHOLD_SMMLV = 4;
    public const EXEMPTION_THRESHOLD_SMMLV = 10;
    public const INTEGRAL_IBC_FACTOR = 0.70;

    /** Tarifas ARL por clase de riesgo. */
    public const ARL_RATES = [
        'I' => 0.00522,
        'II' => 0.01044,
        'III' => 0.02436,
        'IV' => 0.04350,
        'V' => 0.06960,
    ];

    /**
     * Liquida un período: genera (o regenera) un desprendible por cada
     * empleado activo con contrato vigente.
     */
    public function liquidate(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->isPosted()) {
            throw new RuntimeException('La nómina ya está contabilizada y no se puede re-liquidar.');
        }

        $year = $period->start_date?->year ?? now()->year;
        $params = PayrollParameter::forYear($year);
        if (! $params) {
            throw new RuntimeException(
                "No hay parámetros de nómina para el año {$year}. Configúralos en Nómina → Parámetros."
            );
        }

        $employees = Employee::query()
            ->where('status', Employee::STATUS_ACTIVE)
            ->with('activeContract')
            ->get();

        $noveltiesByEmployee = PayrollNovelty::query()
            ->where('payroll_period_id', $period->id)
            ->get()
            ->groupBy('employee_id');

        return DB::transaction(function () use ($period, $employees, $params, $noveltiesByEmployee) {
            // Re-liquidación: borra los desprendibles anteriores (cascade borra líneas).
            $period->slips()->get()->each(fn (PayrollSlip $slip) => $slip->delete());

            foreach ($employees as $employee) {
                if ($employee->activeContract) {
                    $this->buildSlip(
                        $period,
                        $employee,
                        $employee->activeContract,
                        $params,
                        $noveltiesByEmployee->get($employee->id) ?? collect(),
                    );
                }
            }

            $period->update([
                'status' => PayrollPeriod::STATUS_LIQUIDATED,
                'liquidated_at' => now(),
            ]);

            return $period->fresh();
        });
    }

    /**
     * @param  Collection<int,PayrollNovelty>  $novelties
     */
    protected function buildSlip(
        PayrollPeriod $period,
        Employee $employee,
        EmploymentContract $contract,
        PayrollParameter $params,
        Collection $novelties,
    ): void {
        $workedDays = 30;
        $smmlv = (float) $params->smmlv;
        $baseSalary = (float) $contract->salary;
        $integral = $contract->salary_type === 'integral';

        $salaryEarned = round($baseSalary / 30 * $workedDays, 2);

        // Auxilio de transporte: salario ≤ 2 SMMLV, el contrato lo permite y no es integral.
        $transport = 0.0;
        if (! $integral && $contract->transport_allowance_applies && $baseSalary <= 2 * $smmlv) {
            $transport = round((float) $params->transport_allowance / 30 * $workedDays, 2);
        }

        // Novedades: devengados y deducciones manuales del período.
        $extraEarnings = 0.0;       // todos los devengados de novedad
        $extraEarningsIbc = 0.0;    // solo los salariales (entran a IBC y prestaciones)
        $extraDeductions = 0.0;
        foreach ($novelties as $novelty) {
            $amount = round((float) $novelty->amount, 2);
            if ($novelty->isEarning()) {
                $extraEarnings += $amount;
                if ($novelty->affectsIbc()) {
                    $extraEarningsIbc += $amount;
                }
            } else {
                $extraDeductions += $amount;
            }
        }

        $totalEarnings = round($salaryEarned + $transport + $extraEarnings, 2);

        // IBC — base de cotización. No incluye auxilio de transporte; el
        // salario integral cotiza sobre el 70%. Suma los devengados salariales.
        $ibcBase = $integral ? round($salaryEarned * self::INTEGRAL_IBC_FACTOR, 2) : $salaryEarned;
        $ibc = round($ibcBase + $extraEarningsIbc, 2);

        $health = round($ibc * self::RATE_HEALTH_EMP, 2);
        $pension = round($ibc * self::RATE_PENSION_EMP, 2);
        $solidarity = $ibc >= self::SOLIDARITY_THRESHOLD_SMMLV * $smmlv
            ? round($ibc * self::RATE_SOLIDARITY, 2)
            : 0.0;

        $totalDeductions = round($health + $pension + $solidarity + $extraDeductions, 2);
        $netPay = round($totalEarnings - $totalDeductions, 2);

        // Exoneración Ley 1607: salario < 10 SMMLV exonera al empleador de
        // salud, SENA e ICBF.
        $exempt = $baseSalary < self::EXEMPTION_THRESHOLD_SMMLV * $smmlv;

        $erHealth = $exempt ? 0.0 : round($ibc * self::RATE_HEALTH_ER, 2);
        $erPension = round($ibc * self::RATE_PENSION_ER, 2);
        $arlRate = self::ARL_RATES[$contract->risk_class] ?? self::ARL_RATES['I'];
        $erArl = round($ibc * $arlRate, 2);
        $erCaja = round($ibc * self::RATE_CAJA, 2);
        $erSena = $exempt ? 0.0 : round($ibc * self::RATE_SENA, 2);
        $erIcbf = $exempt ? 0.0 : round($ibc * self::RATE_ICBF, 2);

        // Provisiones — base = salario + auxilio + devengados salariales;
        // vacaciones excluye el auxilio de transporte.
        $provBase = $salaryEarned + $transport + $extraEarningsIbc;
        $provCesantias = round($provBase * self::RATE_CESANTIAS, 2);
        $provInterest = round($provBase * self::RATE_INTEREST, 2);
        $provPrima = round($provBase * self::RATE_PRIMA, 2);
        $provVacaciones = round(($salaryEarned + $extraEarningsIbc) * self::RATE_VACACIONES, 2);

        $slip = PayrollSlip::create([
            'company_id' => $period->company_id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'employment_contract_id' => $contract->id,
            'worked_days' => $workedDays,
            'base_salary' => $baseSalary,
            'salary_earned' => $salaryEarned,
            'transport_allowance' => $transport,
            'total_earnings' => $totalEarnings,
            'total_deductions' => $totalDeductions,
            'net_pay' => $netPay,
            'employee_health' => $health,
            'employee_pension' => $pension,
            'solidarity_fund' => $solidarity,
            'employer_health' => $erHealth,
            'employer_pension' => $erPension,
            'employer_arl' => $erArl,
            'employer_caja' => $erCaja,
            'employer_sena' => $erSena,
            'employer_icbf' => $erIcbf,
            'prov_cesantias' => $provCesantias,
            'prov_interest' => $provInterest,
            'prov_prima' => $provPrima,
            'prov_vacaciones' => $provVacaciones,
        ]);

        // Líneas del desprendible.
        $lines = [
            [PayrollSlipLine::TYPE_EARNING, 'salario', 'Salario', $workedDays, $salaryEarned],
        ];
        if ($transport > 0) {
            $lines[] = [PayrollSlipLine::TYPE_EARNING, 'aux_transporte', 'Auxilio de transporte', null, $transport];
        }
        // Devengados de novedad.
        foreach ($novelties as $novelty) {
            if ($novelty->isEarning()) {
                $lines[] = [
                    PayrollSlipLine::TYPE_EARNING,
                    (string) $novelty->concept_code,
                    $novelty->conceptName(),
                    $novelty->quantity !== null ? (float) $novelty->quantity : null,
                    round((float) $novelty->amount, 2),
                ];
            }
        }
        $lines[] = [PayrollSlipLine::TYPE_DEDUCTION, 'salud', 'Salud (4%)', null, $health];
        $lines[] = [PayrollSlipLine::TYPE_DEDUCTION, 'pension', 'Pensión (4%)', null, $pension];
        if ($solidarity > 0) {
            $lines[] = [PayrollSlipLine::TYPE_DEDUCTION, 'fsp', 'Fondo de solidaridad pensional (1%)', null, $solidarity];
        }
        // Deducciones de novedad.
        foreach ($novelties as $novelty) {
            if (! $novelty->isEarning()) {
                $lines[] = [
                    PayrollSlipLine::TYPE_DEDUCTION,
                    (string) $novelty->concept_code,
                    $novelty->conceptName(),
                    $novelty->quantity !== null ? (float) $novelty->quantity : null,
                    round((float) $novelty->amount, 2),
                ];
            }
        }

        foreach ($lines as [$type, $code, $name, $qty, $amount]) {
            PayrollSlipLine::create([
                'payroll_slip_id' => $slip->id,
                'type' => $type,
                'concept_code' => $code,
                'concept_name' => $name,
                'quantity' => $qty,
                'amount' => $amount,
            ]);
        }
    }
}
