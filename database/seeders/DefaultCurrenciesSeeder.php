<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Asegura que cada empresa tenga las 3 monedas más comunes (COP base,
 * USD, EUR). Idempotente: firstOrCreate por (company, code).
 */
class DefaultCurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['code' => 'COP', 'name' => 'Peso colombiano', 'symbol' => '$',  'decimals' => 0, 'is_base' => true],
            ['code' => 'USD', 'name' => 'Dólar estadounidense', 'symbol' => 'US$', 'decimals' => 2, 'is_base' => false],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_base' => false],
        ];

        Company::query()->each(function (Company $company) use ($defaults) {
            foreach ($defaults as $def) {
                Currency::withoutGlobalScopes()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'code' => $def['code'],
                    ],
                    [
                        'name' => $def['name'],
                        'symbol' => $def['symbol'],
                        'decimals' => $def['decimals'],
                        'is_base' => $def['is_base'],
                        'active' => true,
                    ],
                );
            }
        });
    }
}
