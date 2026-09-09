<?php

namespace App\Support;

use App\Models\Company;

/**
 * Dónde puede la empresa hacer descuentos globales (de pie de factura).
 *
 * Apagados por defecto: en un negocio con vendedores, un descuento a toda la
 * factura es una decisión comercial que no todos deben poder tomar. Quien lo
 * necesita lo enciende.
 *
 * El del POS vive aparte, en `pos.allow_discount`, porque ahí gobierna también
 * el descuento por línea y existía desde antes. `PosSettings` lo lee.
 */
class DiscountSettings
{
    public static function allowsOnSaleInvoices(?int $companyId = null): bool
    {
        return self::flag('sales', $companyId);
    }

    public static function allowsOnPurchaseInvoices(?int $companyId = null): bool
    {
        return self::flag('purchases', $companyId);
    }

    private static function flag(string $clave, ?int $companyId): bool
    {
        $company = $companyId
            ? Company::withoutGlobalScopes()->find($companyId)
            : (app(CurrentCompany::class)->get()
                ?? (auth()->user()?->company_id
                    ? Company::withoutGlobalScopes()->find(auth()->user()->company_id)
                    : null));

        return (bool) data_get($company?->settings ?? [], "discounts.{$clave}", false);
    }
}
