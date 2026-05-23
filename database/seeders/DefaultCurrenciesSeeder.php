<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Onboarding\CurrencyProvisioner;
use Illuminate\Database\Seeder;

/**
 * Asegura que cada empresa tenga las monedas estándar (COP base, USD, EUR).
 *
 * Delega en CurrencyProvisioner para no duplicar la lógica. Sigue
 * corriendo en cada deploy para empresas que se crearon antes de que
 * existiera el onboarding automático.
 */
class DefaultCurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(CurrencyProvisioner::class);

        Company::query()->each(function (Company $company) use ($provisioner) {
            $provisioner->provision($company);
        });
    }
}
