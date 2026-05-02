<?php

namespace App\Services;

use App\Models\Company;

/**
 * Verifica si una compañía puede crear más de un recurso según los límites del plan vigente.
 *
 * Uso:
 *   $check = app(PlanLimitChecker::class)->check($company, 'max_locations');
 *   if (! $check['ok']) { ... }
 *
 * Retorno:
 *   ['ok' => bool, 'limit' => int|null, 'current' => int, 'remaining' => int|null]
 *   limit=null significa ilimitado.
 */
class PlanLimitChecker
{
    public function check(Company $company, string $key, ?int $current = null): array
    {
        $limit = $company->planLimit($key);

        if ($limit === null) {
            return [
                'ok' => true,
                'limit' => null,
                'current' => $current ?? $this->countCurrent($company, $key),
                'remaining' => null,
            ];
        }

        $current = $current ?? $this->countCurrent($company, $key);

        return [
            'ok' => $current < $limit,
            'limit' => $limit,
            'current' => $current,
            'remaining' => max(0, $limit - $current),
        ];
    }

    private function countCurrent(Company $company, string $key): int
    {
        return match ($key) {
            'max_locations' => $company->locations()->count(),
            'max_users' => $company->users()->count(),
            'max_third_parties' => \App\Models\ThirdParty::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            // 'max_products' => ... cuando exista Product
            // 'max_invoices_per_month' => ... cuando existan invoices
            default => 0,
        };
    }
}
