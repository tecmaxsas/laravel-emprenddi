<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Onboarding\PaymentMethodProvisioner;
use Illuminate\Database\Seeder;

/**
 * Asegura que cada empresa existente tenga los 8 métodos de pago estándar.
 *
 * Delega en PaymentMethodProvisioner para no duplicar la lógica. Sigue
 * corriendo en cada deploy para empresas que se crearon antes de que
 * existiera el onboarding automático.
 */
class DefaultPaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(PaymentMethodProvisioner::class);

        Company::query()->each(function (Company $company) use ($provisioner) {
            $provisioner->provision($company);
        });
    }
}
