<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

/**
 * Helper único para la feature de garantías. Toggle global se guarda en
 * companies.settings.warranties.enabled.
 *
 *   WarrantiesSettings::enabled()
 */
class WarrantiesSettings
{
    public static function enabled(?Company $company = null): bool
    {
        $company ??= self::currentCompany();
        if (! $company) {
            return false;
        }

        return (bool) data_get($company->settings, 'warranties.enabled', false);
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
