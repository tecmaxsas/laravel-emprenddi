<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;

/**
 * Enciende el modulo de contabilidad en las empresas que ya existen.
 *
 * Contabilidad pasa a ser un modulo asignable para que los negocios pequenos
 * no vean nada contable. Pero quien ya lo esta usando no puede encontrarse con
 * que su contabilidad desaparecio: se activa aqui, dentro del mismo deploy,
 * para que no quede ninguna ventana entre el despliegue y el momento en que
 * alguien lo marque a mano en el superadmin.
 *
 * Las empresas NUEVAS nacen sin el modulo: se les activa desde el superadmin
 * cuando el cliente lo pida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Company::withoutGlobalScopes()->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                $modules = $company->active_modules ?? [];

                if (in_array('accounting', $modules, true)) {
                    continue;
                }

                $modules[] = 'accounting';
                $company->update(['active_modules' => array_values($modules)]);
            }
        });
    }

    public function down(): void
    {
        Company::withoutGlobalScopes()->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                $modules = array_values(array_diff($company->active_modules ?? [], ['accounting']));
                $company->update(['active_modules' => $modules]);
            }
        });
    }
};
