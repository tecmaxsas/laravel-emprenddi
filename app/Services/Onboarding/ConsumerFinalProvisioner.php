<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\ThirdParty;

/**
 * Crea el cliente genérico "Consumidor Final" (CC 222222222) usado
 * por el POS cuando no se identifica al cliente real. Antes se creaba
 * on-the-fly al abrir el POS; ahora forma parte del onboarding para
 * que la empresa ya lo tenga aunque entre primero a otro módulo.
 *
 * NIT 222222222 es la convención DIAN para consumidor final no
 * identificado en operaciones de bajo monto.
 */
class ConsumerFinalProvisioner
{
    public function provision(Company $company): int
    {
        $rec = ThirdParty::withoutGlobalScopes()->firstOrCreate(
            [
                'company_id' => $company->id,
                'document_number' => '222222222',
            ],
            [
                'person_type' => 'natural',
                'document_type' => 'cc',
                'name' => 'Consumidor Final',
                'is_customer' => true,
                'is_supplier' => false,
                'active' => true,
            ],
        );

        return $rec->wasRecentlyCreated ? 1 : 0;
    }
}
