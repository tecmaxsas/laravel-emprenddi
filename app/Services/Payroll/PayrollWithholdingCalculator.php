<?php

namespace App\Services\Payroll;

/**
 * Calcula la retención en la fuente sobre pagos laborales —
 * procedimiento 1, tabla del Art. 383 del Estatuto Tributario.
 *
 * Depuración:
 *   base 1        = total devengado − aportes obligatorios (salud, pensión, FSP)
 *   deducciones   = intereses de vivienda (≤100 UVT) + medicina prepagada
 *                   (≤16 UVT) + dependientes (10% del ingreso, ≤32 UVT)
 *   renta exenta  = 25% de (base 1 − deducciones), tope 240 UVT
 *   tope global   = deducciones + renta exenta ≤ 40% de base 1
 *   base gravable = base 1 − beneficios  → se lleva a UVT y se aplica la tabla
 */
class PayrollWithholdingCalculator
{
    public const EXEMPT_RATE = 0.25;
    public const EXEMPT_CAP_UVT = 240;
    public const BENEFIT_CAP_RATE = 0.40;

    public const HOUSING_CAP_UVT = 100;
    public const PREPAID_HEALTH_CAP_UVT = 16;
    public const DEPENDENTS_RATE = 0.10;
    public const DEPENDENTS_CAP_UVT = 32;

    public const ROUND_TO = 1000;

    /**
     * Tabla Art. 383 ET — en orden descendente.
     * [desde_uvt, tarifa_marginal, retención_acumulada_uvt]
     */
    public const BRACKETS = [
        [2300, 0.39, 770],
        [945, 0.37, 268],
        [640, 0.35, 162],
        [360, 0.33, 69],
        [150, 0.28, 10],
        [95, 0.19, 0],
        [0, 0.00, 0],
    ];

    /**
     * Retención en la fuente del mes, aproximada al múltiplo de mil.
     *
     * @param  float  $grossPay               Total devengado del mes.
     * @param  float  $mandatoryContributions Aportes obligatorios del empleado.
     * @param  float  $uvt                    Valor de la UVT del año.
     * @param  float  $housingInterest        Intereses de vivienda del mes.
     * @param  float  $prepaidHealth          Medicina prepagada del mes.
     * @param  bool   $hasDependents          Si el trabajador tiene dependientes.
     */
    public function compute(
        float $grossPay,
        float $mandatoryContributions,
        float $uvt,
        float $housingInterest = 0,
        float $prepaidHealth = 0,
        bool $hasDependents = false,
    ): float {
        if ($uvt <= 0) {
            return 0.0;
        }

        $base = $grossPay - $mandatoryContributions;
        if ($base <= 0) {
            return 0.0;
        }

        // Deducciones, cada una con su tope.
        $housing = min(max($housingInterest, 0), self::HOUSING_CAP_UVT * $uvt);
        $prepaid = min(max($prepaidHealth, 0), self::PREPAID_HEALTH_CAP_UVT * $uvt);
        $dependents = $hasDependents
            ? min($grossPay * self::DEPENDENTS_RATE, self::DEPENDENTS_CAP_UVT * $uvt)
            : 0.0;
        $deductions = $housing + $prepaid + $dependents;

        // Renta exenta laboral del 25% sobre la base depurada.
        $exempt = min(($base - $deductions) * self::EXEMPT_RATE, self::EXEMPT_CAP_UVT * $uvt);

        // Tope global: deducciones + renta exenta no pueden exceder el 40% de la base.
        $totalBenefit = min($deductions + $exempt, $base * self::BENEFIT_CAP_RATE);

        $taxableBase = max($base - $totalBenefit, 0.0);
        $baseUvt = $taxableBase / $uvt;

        $retentionUvt = 0.0;
        foreach (self::BRACKETS as [$fromUvt, $rate, $accumulatedUvt]) {
            if ($baseUvt > $fromUvt) {
                $retentionUvt = ($baseUvt - $fromUvt) * $rate + $accumulatedUvt;
                break;
            }
        }

        $retention = $retentionUvt * $uvt;

        // La retención se aproxima al múltiplo de mil más cercano.
        return round($retention / self::ROUND_TO) * self::ROUND_TO;
    }
}
