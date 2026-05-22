<?php

namespace App\Services\Payroll;

/**
 * Calcula la retención en la fuente sobre pagos laborales —
 * procedimiento 1, tabla del Art. 383 del Estatuto Tributario.
 *
 * Depuración v1 (simplificada):
 *   base = total devengado − aportes obligatorios (salud, pensión, FSP)
 *   renta exenta laboral = 25% de la base, tope 240 UVT
 *   base gravable = base − renta exenta  → se lleva a UVT y se aplica la tabla
 *
 * No incluye aún deducciones opcionales (intereses de vivienda, medicina
 * prepagada, dependientes), que requieren configuración por empleado.
 */
class PayrollWithholdingCalculator
{
    public const EXEMPT_RATE = 0.25;
    public const EXEMPT_CAP_UVT = 240;
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
     */
    public function compute(float $grossPay, float $mandatoryContributions, float $uvt): float
    {
        if ($uvt <= 0) {
            return 0.0;
        }

        $base = $grossPay - $mandatoryContributions;
        if ($base <= 0) {
            return 0.0;
        }

        $exempt = min($base * self::EXEMPT_RATE, self::EXEMPT_CAP_UVT * $uvt);
        $taxableBase = max($base - $exempt, 0.0);
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
