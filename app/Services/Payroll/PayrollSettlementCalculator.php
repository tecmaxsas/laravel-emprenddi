<?php

namespace App\Services\Payroll;

use App\Models\EmploymentContract;
use App\Models\PayrollSettlement;
use Carbon\Carbon;

/**
 * Calcula la liquidación de prestaciones sociales (cesantías, intereses,
 * prima, vacaciones) según los días trabajados y el salario base.
 *
 * Base de cesantías y prima: salario + auxilio de transporte.
 * Base de vacaciones: solo salario.
 */
class PayrollSettlementCalculator
{
    public const INTEREST_RATE = 0.12;

    /**
     * Días entre dos fechas con la convención colombiana de 360 días
     * (mes de 30 días), inclusivo.
     */
    public function days360(Carbon $start, Carbon $end): int
    {
        $d1 = min($start->day, 30);
        $d2 = min($end->day, 30);

        $days = ($end->year - $start->year) * 360
            + ($end->month - $start->month) * 30
            + ($d2 - $d1) + 1;

        return max($days, 0);
    }

    /**
     * Calcula los componentes de una liquidación.
     *
     * @return array{base:float, cesantias:float, interest:float, prima:float, vacaciones:float, total:float}
     */
    public function calculate(
        EmploymentContract $contract,
        string $type,
        int $workedDays,
        float $smmlv,
        float $transportAllowance,
    ): array {
        $salary = (float) $contract->salary;
        $integral = $contract->salary_type === 'integral';

        $auxilio = (! $integral && $contract->transport_allowance_applies && $salary <= 2 * $smmlv)
            ? $transportAllowance
            : 0.0;
        $base = $salary + $auxilio;

        $wantCesantias = in_array($type, [PayrollSettlement::TYPE_CESANTIAS, PayrollSettlement::TYPE_DEFINITIVA], true);
        $wantInterest = in_array($type, [PayrollSettlement::TYPE_INTERESES, PayrollSettlement::TYPE_DEFINITIVA], true);
        $wantPrima = in_array($type, [PayrollSettlement::TYPE_PRIMA, PayrollSettlement::TYPE_DEFINITIVA], true);
        $wantVacaciones = in_array($type, [PayrollSettlement::TYPE_VACACIONES, PayrollSettlement::TYPE_DEFINITIVA], true);

        // Cesantías del período (también base para los intereses).
        $cesantiasFull = round($base * $workedDays / 360, 2);

        $cesantias = $wantCesantias ? $cesantiasFull : 0.0;
        $interest = $wantInterest
            ? round($cesantiasFull * $workedDays / 360 * self::INTEREST_RATE, 2)
            : 0.0;
        $prima = $wantPrima ? round($base * $workedDays / 360, 2) : 0.0;
        // Vacaciones: 15 días hábiles por año ≈ salario × días / 720.
        $vacaciones = $wantVacaciones ? round($salary * $workedDays / 720, 2) : 0.0;

        return [
            'base' => round($base, 2),
            'cesantias' => $cesantias,
            'interest' => $interest,
            'prima' => $prima,
            'vacaciones' => $vacaciones,
            'total' => round($cesantias + $interest + $prima + $vacaciones, 2),
        ];
    }
}
