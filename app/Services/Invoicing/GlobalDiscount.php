<?php

namespace App\Services\Invoicing;

use Illuminate\Database\Eloquent\Model;

/**
 * El descuento global de una factura: el de pie de página, sobre toda la venta.
 *
 * **Se aplica sobre la base, no sobre el total.** Primero baja la base gravable
 * y después se calcula el IVA. No es un detalle: sobre $1.000.000 con IVA del
 * 19 % y un 10 % de descuento, hacerlo sobre el total cobraría $19.000 de IVA
 * que la DIAN no debe recibir.
 *
 * Se reparte entre las líneas en proporción a lo que pesa cada una, y se suma
 * al `discount_amount` de cada línea. Esa decisión está explicada en la
 * migración `2026_09_16_100100`: en resumen, doce lugares del sistema calculan
 * la base como `subtotal - discount_amount`, y metiéndolo ahí todos quedan
 * bien sin tocarlos.
 *
 * Sirve igual para ventas y compras: las dos tablas tienen la misma forma.
 */
class GlobalDiscount
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    public const TYPES = [
        self::TYPE_PERCENT => 'Porcentaje (%)',
        self::TYPE_AMOUNT => 'Valor fijo ($)',
    ];

    /**
     * Reparte el descuento global entre las líneas de la factura.
     *
     * Las guarda y devuelve el monto total efectivamente descontado, que puede
     * diferir en centavos del valor pedido por el redondeo de la repartición
     * —el remanente se le carga a la línea más grande para que la suma cuadre
     * al peso—.
     *
     * Es idempotente: llamarla dos veces no descuenta dos veces.
     *
     * @param  Model  $invoice  con `lines`, `global_discount_type` y `global_discount_value`
     */
    public function aplicar(Model $invoice): float
    {
        $lineas = $invoice->lines;

        if ($lineas->isEmpty()) {
            return 0.0;
        }

        // La base de cada línea SIN el global: se reconstruye restando lo que
        // se le hubiera repartido en un cálculo anterior. Sin esto, recalcular
        // dos veces descontaría dos veces.
        $bases = $lineas->values()->map(function ($linea) {
            $propio = max(0, round((float) $linea->discount_amount - (float) $linea->global_discount_amount, 2));

            return [
                'linea' => $linea,
                'propio' => $propio,
                'base' => max(0, round((float) $linea->subtotal - $propio, 2)),
            ];
        });

        $baseTotal = round($bases->sum('base'), 2);
        $descuento = $this->montoSobre($invoice, $baseTotal);

        // Reparto proporcional al peso de cada línea.
        $partes = [];
        $repartido = 0.0;
        $mayor = 0;

        foreach ($bases as $i => $b) {
            $partes[$i] = $baseTotal > 0 ? round($descuento * $b['base'] / $baseTotal, 2) : 0.0;
            $repartido += $partes[$i];

            if ($b['base'] > $bases[$mayor]['base']) {
                $mayor = $i;
            }
        }

        // El redondeo del reparto deja centavos sueltos. Se le cargan a la
        // línea más grande para que la suma cuadre al peso con lo pactado.
        $remanente = round($descuento - $repartido, 2);

        if (abs($remanente) >= 0.01) {
            $partes[$mayor] = round($partes[$mayor] + $remanente, 2);
        }

        foreach ($bases as $i => $b) {
            $this->escribir($b['linea'], $b['propio'], $partes[$i]);
        }

        return $descuento;
    }

    /**
     * Cuánto se descuenta, dada la base. Un porcentaje se aplica; un valor fijo
     * se recorta a la base, porque descontar más de lo que vale la factura
     * dejaría totales negativos.
     */
    public function montoSobre(Model $invoice, float $base): float
    {
        $valor = max(0, (float) $invoice->global_discount_value);

        if ($valor <= 0 || $base <= 0) {
            return 0.0;
        }

        if ($invoice->global_discount_type === self::TYPE_AMOUNT) {
            return round(min($valor, $base), 2);
        }

        return round($base * min(100, $valor) / 100, 2);
    }

    /** Deja la línea con su descuento propio más la parte que le tocó. */
    private function escribir(Model $linea, float $propio, float $parte): void
    {
        $base = max(0, round((float) $linea->subtotal - $propio - $parte, 2));
        $tasa = (float) ($linea->tax_rate ?? 0);
        $impuesto = round($base * $tasa / 100, 2);

        $linea->forceFill([
            'discount_amount' => round($propio + $parte, 2),
            'global_discount_amount' => $parte,
            'tax_amount' => $impuesto,
            'total' => round($base + $impuesto, 2),
        ])->save();
    }
}
