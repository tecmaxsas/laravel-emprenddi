<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

/**
 * Helper para la configuracion del modulo Parqueadero. Todo se guarda
 * en company.settings.parking.*
 *
 *   ParkingSettings::showQr()           mostrar QR en el ticket de entrada
 *   ParkingSettings::config()           todo el bloque como array
 */
class ParkingSettings
{
    public static function showQr(?Company $company = null): bool
    {
        return self::config($company)['show_qr'];
    }

    public static function config(?Company $company = null): array
    {
        $company ??= self::currentCompany();
        $settings = $company?->settings ?? [];

        return [
            'show_qr' => (bool) data_get($settings, 'parking.show_qr', true),
        ];
    }

    protected static function currentCompany(): ?Company
    {
        $companyId = Auth::user()?->company_id;
        return $companyId ? Company::find($companyId) : null;
    }
}
