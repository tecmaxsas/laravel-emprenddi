<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Crea las monedas más comunes para una empresa nueva:
 * COP (base), USD y EUR. Idempotente — usa firstOrCreate por (company, code).
 *
 * Si la empresa registró otra moneda como base (raro en CO pero soportado),
 * la dejamos como base y COP entra como no-base.
 */
class CurrencyProvisioner
{
    public function provision(Company $company): int
    {
        $baseCode = strtoupper($company->currency ?: 'COP');

        $defaults = [
            ['code' => 'COP', 'name' => 'Peso colombiano',       'symbol' => '$',   'decimals' => 0],
            ['code' => 'USD', 'name' => 'Dólar estadounidense',  'symbol' => 'US$', 'decimals' => 2],
            ['code' => 'EUR', 'name' => 'Euro',                  'symbol' => '€',   'decimals' => 2],
        ];

        $created = 0;
        DB::transaction(function () use ($company, $defaults, $baseCode, &$created) {
            foreach ($defaults as $def) {
                $rec = Currency::withoutGlobalScopes()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'code' => $def['code'],
                    ],
                    [
                        'name' => $def['name'],
                        'symbol' => $def['symbol'],
                        'decimals' => $def['decimals'],
                        'is_base' => $def['code'] === $baseCode,
                        'active' => true,
                    ],
                );
                if ($rec->wasRecentlyCreated) {
                    $created++;
                }
            }
        });

        return $created;
    }
}
