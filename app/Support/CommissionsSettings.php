<?php

namespace App\Support;

use App\Models\Company;

/**
 * Feature flags + parametros del modulo de comisiones por vendedor.
 * Almacenados en companies.settings.commissions.* (jsonb).
 *
 * El admin activa el modulo desde Configuraciones → Comisiones. Una vez
 * activo, define la base de calculo, el momento de causacion y las
 * cuentas contables. Las reglas de % por vendedor se gestionan en
 * Ventas → Comisiones (CommissionRuleResource).
 */
class CommissionsSettings
{
    public const BASE_SUBTOTAL = 'subtotal';   // subtotal sin IVA (recomendado)
    public const BASE_TOTAL = 'total';         // total con IVA
    public const BASE_PROFIT = 'profit';       // utilidad (venta - costo)

    public const CAUSATION_INVOICED = 'invoiced';   // al postear la factura
    public const CAUSATION_COLLECTED = 'collected';  // al quedar totalmente pagada

    /**
     * Definicion de cada setting para construir la UI y resolver defaults.
     */
    public const FEATURES = [
        'enabled' => [
            'label' => 'Activar comisiones por vendedor',
            'description' => 'Habilita el cálculo y liquidación de comisiones. Una vez activo, configura las reglas en Ventas → Comisiones.',
            'default' => false,
        ],
        'base' => [
            'label' => 'Base de cálculo',
            'description' => 'Sobre qué valor de la venta se calcula la comisión.',
            'default' => self::BASE_SUBTOTAL,
        ],
        'causation' => [
            'label' => 'Momento de causación',
            'description' => 'Cuándo gana el vendedor la comisión: al emitir la factura o cuando el cliente paga totalmente.',
            'default' => self::CAUSATION_COLLECTED,
        ],
        'expense_account_id' => [
            'label' => 'Cuenta de gasto de comisiones',
            'description' => 'Cuenta del PUC donde se registra el gasto al liquidar (débito). Recomendado: 530515 Comisiones (gastos de ventas).',
            'default' => null,
        ],
        'payable_account_id' => [
            'label' => 'Cuenta de comisiones por pagar',
            'description' => 'Cuenta del PUC del pasivo a pagar al vendedor (crédito). Recomendado: 233520 Comisiones.',
            'default' => null,
        ],
    ];

    public static function get(string $key): mixed
    {
        if (! array_key_exists($key, self::FEATURES)) {
            return null;
        }
        $company = app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
        $settings = $company?->settings ?? [];
        $stored = data_get($settings, "commissions.{$key}");
        return $stored ?? self::FEATURES[$key]['default'];
    }

    public static function isEnabled(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function moduleActive(): bool
    {
        return self::isEnabled('enabled');
    }

    public static function base(): string
    {
        return (string) (self::get('base') ?: self::BASE_SUBTOTAL);
    }

    public static function causation(): string
    {
        return (string) (self::get('causation') ?: self::CAUSATION_COLLECTED);
    }

    /** Cuenta de gasto, resolviendo: configurada → fallback 530515 → null. */
    public static function expenseAccountId(): ?int
    {
        return self::resolveAccount('expense_account_id', '530515');
    }

    /** Cuenta de pasivo, resolviendo: configurada → fallback 233520 → null. */
    public static function payableAccountId(): ?int
    {
        return self::resolveAccount('payable_account_id', '233520');
    }

    private static function resolveAccount(string $key, string $fallbackCode): ?int
    {
        $configured = self::get($key);
        if ($configured) {
            return (int) $configured;
        }
        $company = app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
        if (! $company) return null;

        $id = \App\Models\Account::query()
            ->where('company_id', $company->id)
            ->where('code', $fallbackCode)
            ->where('active', true)
            ->value('id');
        return $id ? (int) $id : null;
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $key) {
            $out[$key] = self::get($key);
        }
        return $out;
    }
}
