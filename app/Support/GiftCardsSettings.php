<?php

namespace App\Support;

use App\Models\Company;

/**
 * Feature flags del modulo de tarjetas regalo / bonos. Almacenados en
 * companies.settings.gift_cards.* (jsonb).
 *
 * El admin de la empresa activa el modulo desde Configuraciones → Gift Cards.
 * Una vez activo (enabled = true), el sistema:
 *  - Habilita el resource GiftCardResource en el sidebar
 *  - Permite vender gift cards desde POS (se selecciona el "producto" gift card)
 *  - Permite redimir gift cards en POS como medio de pago
 */
class GiftCardsSettings
{
    public const FEATURES = [
        'enabled' => [
            'label' => 'Activar tarjetas regalo (Gift Cards)',
            'description' => 'Habilita la venta y redencion de gift cards en POS.',
            'default' => false,
        ],
        'allow_partial_redemption' => [
            'label' => 'Permitir redencion parcial',
            'description' => 'Si esta activo, el cliente puede usar parte del saldo en una venta y guardar el resto para otra. Si esta inactivo, debe usar todo el saldo de una.',
            'default' => true,
        ],
        'require_recipient_data' => [
            'label' => 'Pedir datos del destinatario al emitir',
            'description' => 'Al vender una gift card, pide nombre y email/telefono del receptor para registrar quien la recibe.',
            'default' => false,
        ],
        'send_email_on_issue' => [
            'label' => 'Enviar email al emitir',
            'description' => 'Cuando se vende una gift card y se ingresa el email del destinatario, se envia automaticamente con el codigo y saldo. Requiere SMTP configurado en la empresa.',
            'default' => false,
        ],
        'allow_topup' => [
            'label' => 'Permitir recargar saldo',
            'description' => 'Si esta activo, una gift card existente puede recargarse con mas saldo (en lugar de emitir una nueva). Util para programas de fidelizacion.',
            'default' => false,
        ],
        'default_expiry_months' => [
            'label' => 'Meses por defecto de vigencia',
            'description' => 'Cuantos meses dura una gift card desde su emision (0 = sin expiracion). El cajero puede sobrescribir al venderla.',
            'default' => 12,
        ],
    ];

    /**
     * Si la feature esta habilitada. Para 'default_expiry_months' devuelve
     * el numero almacenado o el default (puede usarse con (int) cast).
     */
    public static function get(string $feature): mixed
    {
        if (! array_key_exists($feature, self::FEATURES)) {
            return null;
        }

        $company = app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
        $settings = $company?->settings ?? [];

        $stored = data_get($settings, "gift_cards.{$feature}");
        if ($stored === null) {
            return self::FEATURES[$feature]['default'];
        }
        return $stored;
    }

    /** Para features booleanas. */
    public static function isEnabled(string $feature): bool
    {
        return (bool) self::get($feature);
    }

    /** Estado actual de todas las features. */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $key) {
            $out[$key] = self::get($key);
        }
        return $out;
    }

    /** Shortcut conveniente — el modulo esta activo en su totalidad? */
    public static function moduleActive(): bool
    {
        return self::isEnabled('enabled');
    }
}
