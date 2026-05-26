<?php

namespace App\Support;

use App\Models\Company;

/**
 * Feature flags del modulo de promociones. Almacenados en
 * companies.settings.promotions.* (jsonb).
 *
 * El admin de la empresa activa el modulo desde Configuraciones → Promociones.
 * Una vez activo (enabled = true), todos los sub-toggles arrancan en su default
 * y el admin puede apagar los tipos que no use (ej. apagar 'bogo' si nunca
 * hace 2x1, dejar solo 'percentage' y 'fixed_amount').
 *
 * El motor de aplicacion (PromotionEngine) consulta isEnabled('enabled')
 * antes de procesar; si esta false, devuelve sin hacer nada.
 */
class PromotionsSettings
{
    public const FEATURES = [
        // Master switch — si false, todo el modulo esta apagado
        'enabled' => [
            'label' => 'Activar promociones',
            'description' => 'Habilita el modulo completo de promociones y descuentos automaticos.',
            'default' => false,
        ],
        // Tipos de promocion
        'percentage' => [
            'label' => 'Descuentos por porcentaje',
            'description' => 'Promociones tipo "10% off en bebidas" o "20% off en compras > $50K".',
            'default' => true,
        ],
        'fixed_amount' => [
            'label' => 'Descuentos de monto fijo',
            'description' => 'Promociones tipo "$5.000 off compras > $50K".',
            'default' => true,
        ],
        'coupons' => [
            'label' => 'Cupones con código',
            'description' => 'Cupones que el cliente ingresa manualmente en POS (ej. "BIENVENIDO10"). Con limites de uso total y por cliente.',
            'default' => true,
        ],
        'bogo' => [
            'label' => '2x1 / 3x2 (compra X lleva Y)',
            'description' => 'Promociones tipo "compra 2 hamburguesas, paga 1" (la mas barata gratis). Cubre BOGO en sus variantes.',
            'default' => true,
        ],
        'volume_tier' => [
            'label' => 'Descuento por volumen escalonado',
            'description' => 'Niveles segun cantidad (ej. 4-9 unidades 5%, 10-19 unidades 10%, 20+ unidades 15%). Util para mayoristas y distribuidoras.',
            'default' => true,
        ],
        'bundle' => [
            'label' => 'Combos / paquetes',
            'description' => 'Conjunto de productos especificos a precio fijo (ej. "Hamburguesa + Papas + Bebida = $25.000").',
            'default' => true,
        ],
        'happy_hour' => [
            'label' => 'Happy Hour (rango horario)',
            'description' => 'Promociones limitadas a dias y horas especificas (ej. "20% off bebidas Lun-Vie 5-7 PM"). Util para bares y restaurantes.',
            'default' => true,
        ],
        // Comportamiento global
        'allow_stacking' => [
            'label' => 'Permitir apilar promociones',
            'description' => 'Si esta activo, mas de una promocion puede aplicarse a la misma venta (solo las marcadas como apilables). Si esta inactivo, solo aplica una a la vez (la de mayor prioridad).',
            'default' => false,
        ],
        'show_in_receipt' => [
            'label' => 'Mostrar descuentos en el ticket',
            'description' => 'Imprime el nombre de la promocion y el monto descontado en la tirilla/factura.',
            'default' => true,
        ],
    ];

    /**
     * Si la feature esta habilitada para la empresa actual.
     * Si la empresa no tiene config, respeta el default.
     */
    public static function isEnabled(string $feature): bool
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return false;
        }

        $company = app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
        $settings = $company?->settings ?? [];

        $stored = data_get($settings, "promotions.{$feature}");
        if ($stored === null) {
            return (bool) self::FEATURES[$feature]['default'];
        }

        return (bool) $stored;
    }

    /** Estado actual de todas las features. */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $key) {
            $out[$key] = self::isEnabled($key);
        }
        return $out;
    }

    /** Shortcut conveniente — el modulo esta activo en su totalidad? */
    public static function moduleActive(): bool
    {
        return self::isEnabled('enabled');
    }
}
