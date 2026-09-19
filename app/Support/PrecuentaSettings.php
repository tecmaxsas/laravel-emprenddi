<?php

namespace App\Support;

use App\Models\Company;

/**
 * La precuenta: lo que se le lleva al cliente antes de cobrarle.
 *
 * El mesero imprime lo consumido, el cliente revisa que esté bien, y solo
 * entonces se factura. Sin ella se factura primero y se discute después, que
 * es cuando el error cuesta una anulación —o una nota crédito, si la factura
 * ya se fue a la DIAN—.
 *
 * QUÉ NO ES
 *
 * No es una factura. Es la pieza donde más fácil se confunde eso, porque se
 * imprime en la misma impresora, se parece y lleva los mismos totales. En
 * Colombia entregar un documento que parezca factura sin serlo es un problema
 * con la DIAN, así que la leyenda que lo advierte **no es configurable**: se
 * imprime siempre, y está escrita en la vista, no aquí.
 *
 * QUÉ SÍ ES CONFIGURABLE
 *
 * El texto del pie. Cada restaurante pone lo suyo —lo más común es la
 * advertencia de la propina, que en Colombia es voluntaria y el cliente puede
 * modificar o no pagar—. Es texto libre a propósito: la ley cambia, los
 * negocios tienen políticas distintas, y adivinar el texto correcto para todos
 * sería quedarse corto para la mitad.
 */
class PrecuentaSettings
{
    /**
     * El pie por defecto.
     *
     * Dice lo que la ley colombiana obliga a advertir sobre la propina: que es
     * voluntaria y que el cliente decide. Se ofrece escrito porque un campo
     * vacío se queda vacío, y entonces la advertencia no sale.
     */
    public const PIE_POR_DEFECTO =
        'La propina sugerida es del 10% y es VOLUNTARIA. '
        ."Usted puede modificarla, no pagarla, o dejar la que considere.\n"
        .'Verifique su consumo antes de solicitar la factura.';

    public const PROPINA_POR_DEFECTO = 10.0;

    /**
     * @return array{enabled: bool, footer: string, tip_percent: float, show_tip: bool}
     */
    public static function config(?Company $company = null): array
    {
        $company ??= self::empresaActual();
        $settings = $company?->settings ?? [];

        return [
            'enabled' => (bool) data_get($settings, 'restaurant.precheck.enabled', true),
            'footer' => trim((string) data_get(
                $settings, 'restaurant.precheck.footer', self::PIE_POR_DEFECTO
            )),
            'tip_percent' => (float) data_get(
                $settings, 'restaurant.precheck.tip_percent', self::PROPINA_POR_DEFECTO
            ),
            'show_tip' => (bool) data_get($settings, 'restaurant.precheck.show_tip', true),
        ];
    }

    public static function activa(?Company $company = null): bool
    {
        return self::config($company)['enabled'];
    }

    public static function pie(?Company $company = null): string
    {
        return self::config($company)['footer'];
    }

    /**
     * La propina sugerida sobre un consumo.
     *
     * Se calcula sobre el total del consumo y se redondea a peso: sugerir
     * «$4.733,50» es una cifra que nadie va a dejar sobre la mesa.
     */
    public static function propinaSugerida(float $total, ?Company $company = null): float
    {
        $config = self::config($company);

        if (! $config['show_tip'] || $config['tip_percent'] <= 0) {
            return 0.0;
        }

        return round($total * $config['tip_percent'] / 100, 0);
    }

    /**
     * Lo que se guarda desde la pantalla de configuración.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function paraGuardar(array $state): array
    {
        return [
            'enabled' => (bool) ($state['restaurant_precheck_enabled'] ?? true),
            'footer' => trim((string) ($state['restaurant_precheck_footer'] ?? '')),
            // Entre 0 y 100: una propina del 500% no es un error que valga la
            // pena imprimir en la mesa de un cliente.
            'tip_percent' => max(0, min(100, (float) ($state['restaurant_precheck_tip_percent'] ?? 0))),
            'show_tip' => (bool) ($state['restaurant_precheck_show_tip'] ?? true),
        ];
    }

    private static function empresaActual(): ?Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company) {
            return $company;
        }

        $companyId = auth()->user()?->company_id;

        return $companyId ? Company::withoutGlobalScopes()->find($companyId) : null;
    }
}
