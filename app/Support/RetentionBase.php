<?php

namespace App\Support;

/**
 * Sobre qué monto se calcula cada retención.
 *
 * No es lo mismo para todas, y ahí es fácil equivocarse:
 *
 *   - **Retefuente y ReteICA** van sobre el subtotal ANTES de IVA, descontando
 *     los descuentos.
 *   - **ReteIVA** va sobre el IVA, no sobre el subtotal. Aplicarle la tarifa al
 *     subtotal multiplicaría la retención por varias veces su valor —y nadie lo
 *     nota hasta que el cliente reclama que le retuvieron de más—.
 *
 * Vive aquí y no en cada pantalla porque la regla ya se había escrito dos veces:
 * la factura de venta la tenía bien y toma de pedidos aplicaba la misma base a
 * todas las retenciones. Dos copias de una regla fiscal terminan separándose, y
 * la que se queda atrás produce cifras que parecen correctas.
 */
class RetentionBase
{
    /** Las que se calculan sobre el IVA en lugar del subtotal. */
    public const SOBRE_EL_IVA = ['vat_withholding'];

    public static function esSobreElIva(?string $tipoDeImpuesto): bool
    {
        return in_array($tipoDeImpuesto, self::SOBRE_EL_IVA, true);
    }

    /**
     * La base que corresponde, dados el subtotal neto y el IVA del documento.
     *
     * @param  float  $subtotalNeto  Subtotal menos descuentos.
     * @param  float  $iva  IVA total del documento.
     */
    public static function para(?string $tipoDeImpuesto, float $subtotalNeto, float $iva): float
    {
        return round(
            self::esSobreElIva($tipoDeImpuesto) ? $iva : $subtotalNeto,
            2,
        );
    }

    /**
     * La misma regla, leyendo las líneas de un documento.
     *
     * @param  iterable<int, array<string, mixed>>|null  $lineas
     */
    public static function deLineas(?string $tipoDeImpuesto, ?iterable $lineas): float
    {
        $lineas = collect($lineas ?? []);

        $iva = (float) $lineas->sum(fn ($l) => (float) ($l['tax_amount'] ?? 0));

        $subtotalNeto = (float) $lineas->sum(fn ($l) => (float) ($l['subtotal'] ?? 0))
            - (float) $lineas->sum(fn ($l) => (float) ($l['discount_amount'] ?? 0));

        return self::para($tipoDeImpuesto, $subtotalNeto, $iva);
    }
}
