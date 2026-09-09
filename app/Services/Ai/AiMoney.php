<?php

namespace App\Services\Ai;

/**
 * Cómo se muestran los dólares del monedero de Claude.
 *
 * Vive aparte porque hay una decisión que se repite en tres pantallas: una
 * recarga son US$ 50,00 pero una respuesta corta cuesta US$ 0,0008, y las dos
 * cosas van en la misma columna. Formatear todo a dos decimales convertiría
 * cada consumo en «US$ 0,00», que parece gratis; formatear todo a seis llenaría
 * de ceros los saldos. Se decide por el valor.
 */
class AiMoney
{
    /** Un valor en dólares, con los decimales que de verdad necesita. */
    public static function usd(float $valor): string
    {
        $abs = abs($valor);

        $decimales = match (true) {
            $abs === 0.0 => 2,
            $abs < 0.01 => 4,
            default => 2,
        };

        return 'US$ '.number_format($valor, $decimales, ',', '.');
    }

    /**
     * El equivalente aproximado en pesos, para un cliente colombiano.
     *
     * Es una referencia, no el cobro: el monedero se lleva en dólares y la tasa
     * solo sirve para que alguien se haga una idea de cuánto es.
     */
    public static function cop(float $usd): string
    {
        return '$'.number_format($usd * (float) config('ai.usd_to_cop'), 0, ',', '.');
    }
}
