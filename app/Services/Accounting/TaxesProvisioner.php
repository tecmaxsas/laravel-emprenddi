<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;

class TaxesProvisioner
{
    /**
     * Provisiona los impuestos colombianos típicos para una compañía.
     * Idempotente: no duplica los que ya existan por código.
     *
     * Resuelve los códigos de cuenta del catálogo (e.g. '240805') a los
     * IDs reales de Account de la compañía. Si la cuenta no existe,
     * el impuesto queda con account NULL (el usuario lo configura después).
     *
     * @return int número de impuestos creados.
     */
    public function provision(Company $company): int
    {
        $catalog = TaxesCatalog::standard();
        $created = 0;

        DB::transaction(function () use ($company, $catalog, &$created) {
            $existingCodes = Tax::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->pluck('code')
                ->all();

            $accountsByCode = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->pluck('id', 'code')
                ->all();

            foreach ($catalog as $entry) {
                if (in_array($entry['code'], $existingCodes, true)) {
                    continue;
                }

                Tax::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'code' => $entry['code'],
                    'name' => $entry['name'],
                    'type' => $entry['type'],
                    'rate' => $entry['rate'],
                    'sale_account_id' => $entry['sale_account_code']
                        ? ($accountsByCode[$entry['sale_account_code']] ?? null)
                        : null,
                    'purchase_account_id' => $entry['purchase_account_code']
                        ? ($accountsByCode[$entry['purchase_account_code']] ?? null)
                        : null,
                    'minimum_base' => $entry['minimum_base'] ?? 0,
                    'applies_to' => $entry['applies_to'] ?? 'both',
                    'is_default_for_sale' => $entry['is_default_for_sale'] ?? false,
                    'is_default_for_purchase' => $entry['is_default_for_purchase'] ?? false,
                    'is_active' => true,
                    'is_system' => true,
                    'description' => $entry['description'] ?? null,
                ]);
                $created++;
            }
        });

        return $created;
    }
}
