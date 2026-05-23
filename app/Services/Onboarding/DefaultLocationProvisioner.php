<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\Location;

/**
 * Crea la "Sede Principal" para una empresa si no tiene ninguna location.
 * Idempotente — si ya hay locations no toca nada (no asume que la principal
 * deba llamarse de cierta forma; respeta lo que el usuario haya creado).
 *
 * Copia los datos de dirección de la empresa para precargar la sede.
 */
class DefaultLocationProvisioner
{
    public function provision(Company $company): int
    {
        $alreadyHas = Location::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->exists();

        if ($alreadyHas) {
            return 0;
        }

        Location::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'PRINCIPAL',
            'name' => 'Sede Principal',
            'type' => 'mixed',
            'is_main' => true,
            'address' => $company->address,
            'city' => $company->city,
            'department' => $company->department,
            'country' => $company->country ?: 'CO',
            'phone' => $company->phone,
            'currency' => $company->currency ?: 'COP',
            'timezone' => $company->timezone ?: 'America/Bogota',
            'active' => true,
        ]);

        return 1;
    }
}
