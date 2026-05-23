<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

/**
 * Helper único para decidir si el feature de seriales está activo
 * para la empresa actual y si un producto concreto lo usa.
 *
 *   SerialsSettings::enabled()           // toggle global de la empresa
 *   SerialsSettings::productUses($p)     // toggle global AND producto.tracks_serials
 *
 * Tener una sola fuente de verdad evita que las distintas UIs
 * (ProductResource, Repeater de compras, POS, Settings) se desincronicen.
 */
class SerialsSettings
{
    public static function enabled(?Company $company = null): bool
    {
        $company ??= self::currentCompany();
        if (! $company) {
            return false;
        }

        return (bool) data_get($company->settings, 'serials.enabled', false);
    }

    public static function productUses(?Product $product): bool
    {
        if (! $product) {
            return false;
        }

        return self::enabled() && (bool) $product->tracks_serials;
    }

    protected static function currentCompany(): ?Company
    {
        $companyId = Auth::user()?->company_id;
        if (! $companyId) {
            return null;
        }

        return Company::find($companyId);
    }
}
