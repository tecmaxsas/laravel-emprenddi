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

    /**
     * Subcuenta de subsistencia del Fondo de Solidaridad Pensional.
     *
     * Va ADEMAS del 1% de solidaridad y sube por tramos desde 16 SMLMV
     * (Ley 797 de 2003, art. 8). Sin ella, a los salarios altos se les
     * descuenta de menos y el aporte queda mal reportado.
     *
     * [desde SMLMV (exclusive) => tarifa]
     */
    public const SUBSISTENCE_BRACKETS = [
        20 => 0.010,
        19 => 0.008,
        18 => 0.006,
        17 => 0.004,
        16 => 0.002,
    ];
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

    public function __construct(protected PayrollWithholdingCalculator $withholding) {}

    /**
     * Liquida un período: genera (o regenera) un desprendible por cada
     * empleado activo con contrato vigente.
     */
    /**
     * Tarifa de la subcuenta de subsistencia segun el IBC.
     *
     * Los tramos se recorren de mayor a menor y gana el primero que aplica,
     * asi que basta con comparar contra el limite inferior de cada uno.
     */
    protected function subsistenceRate(float $ibc, float $smmlv): float
    {
        if ($smmlv <= 0) {
            return 0.0;
        }

        $enSmmlv = $ibc / $smmlv;

        foreach (self::SUBSISTENCE_BRACKETS as $desde => $tarifa) {
            if ($enSmmlv > $desde) {
                return $tarifa;
            }
        }

        return 0.0;
    }

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

        // Scope explicito por company del period. Si el engine corre desde
        // queue/artisan sin CurrentCompany hidratado, el scope global esta
        // inerte y liquidaria empleados de OTRAS empresas bajo el company
        // del period. Defense in depth critico.
        $employees = Employee::query()
            ->where('company_id', $period->company_id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->with('activeContract')
            ->get();

        $noveltiesByEmployee = PayrollNovelty::query()
            ->where('company_id', $period->company_id)
            ->where('payroll_period_id', $period->id)
            ->get()
            ->groupBy('employee_id');

        return DB::transaction(function () use ($period, $employees, $params, $noveltiesByEmployee) {
            // Re-liquidación: borra los desprendibles anteriores (cascade borra líneas).
            // Lo que ya se reporto a la DIAN se guarda antes de borrar: el
            // CUNE es el unico enlace con el documento que la DIAN emitio, y
            // una nota de ajuste lo necesita como predecesor. Perderlo deja a
            // esa nomina sin forma legal de corregirse.
            $reportadas = $this->rastroDian($period);

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

            $this->devolverRastroDian($period, $reportadas);

            $period->update([
                'status' => PayrollPeriod::STATUS_LIQUIDATED,
                'liquidated_at' => now(),
            ]);

            return $period->fresh();
        });
    }

    /**
     * Lo que la DIAN ya sabe de cada empleado en este periodo.
     *
     * Se toma antes de borrar los desprendibles para re-liquidar.
     *
     * @return array<int, array<string, mixed>>  empleado => datos DIAN
     */
    protected function rastroDian(PayrollPeriod $period): array
    {
        return $period->slips()
            ->whereNotNull('cune')
            ->get()
            ->keyBy('employee_id')
            ->map(fn (PayrollSlip $slip) => [
                'prefix' => $slip->prefix,
                'consecutive' => $slip->consecutive,
                'cune' => $slip->cune,
                'qr_url' => $slip->qr_url,
                'dian_status' => $slip->dian_status,
                'dian_status_code' => $slip->dian_status_code,
                'dian_sent_at' => $slip->dian_sent_at,
                'dian_response' => $slip->dian_response,
                'net_pay' => (float) $slip->net_pay,
            ])
            ->all();
    }

    /**
     * Devuelve el rastro DIAN al desprendible recalculado del mismo empleado.
     *
     * Si el neto cambio, queda marcado: lo reportado y lo liquidado ya no
     * coinciden y hay que emitir una nota de ajuste. Reenviar la nomina no es
     * opcion — la DIAN no acepta dos veces el mismo documento.
     *
     * Si el empleado ya no se liquida (contrato terminado, por ejemplo) el
     * desprendible reportado se vuelve a crear tal cual, para no perder el
     * documento que la DIAN tiene.
     *
     * @param  array<int, array<string, mixed>>  $reportadas
     */
    protected function devolverRastroDian(PayrollPeriod $period, array $reportadas): void
    {
        foreach ($reportadas as $employeeId => $datos) {
            $netoAnterior = $datos['net_pay'];
            unset($datos['net_pay']);

            $slip = $period->slips()->where('employee_id', $employeeId)->first();

            if (! $slip) {
                continue;
            }

            $slip->forceFill([
                ...$datos,
                'dian_needs_adjustment' => abs((float) $slip->net_pay - $netoAnterior) > 0.01,
            ])->save();
        }
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
        $subsistence = round($ibc * $this->subsistenceRate($ibc, $smmlv), 2);

        // Retención en la fuente: automática (Art. 383 ET), salvo que el
        // empleado tenga una novedad manual de retención que la sustituya.
        $hasManualRetention = $novelties->contains(
            fn (PayrollNovelty $n) => ! $n->isEarning() && $n->concept_code === 'retencion_fuente'
        );
        $autoRetention = $hasManualRetention
            ? 0.0
            : $this->withholding->compute(
                $totalEarnings,
                $health + $pension + $solidarity + $subsistence,
                (float) $params->uvt,
                (float) ($employee->housing_interest_deduction ?? 0),
                (float) ($employee->prepaid_health_deduction ?? 0),
                (bool) $employee->has_dependents,
            );

        $totalDeductions = round(
            $health + $pension + $solidarity + $subsistence + $extraDeductions + $autoRetention,
            2,
        );
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
            'solidarity_sub' => $subsistence,
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
        if ($subsistence > 0) {
            $lines[] = [PayrollSlipLine::TYPE_DEDUCTION, 'fsp_sub', 'Fondo de subsistencia', null, $subsistence];
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
        // Retención en la fuente calculada automáticamente.
        if ($autoRetention > 0) {
            $lines[] = [PayrollSlipLine::TYPE_DEDUCTION, 'retencion_fuente', 'Retención en la fuente', null, $autoRetention];
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
