<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class PucProvisioner
{
    /**
     * Provisiona el plan único de cuentas estándar para una compañía.
     * Idempotente: si ya tiene cuentas con esos códigos, no las duplica.
     *
     * @return int número de cuentas creadas (no contadas las que ya existían).
     */
    public function provision(Company $company): int
    {
        $catalog = PucCatalog::standard();
        $created = 0;

        DB::transaction(function () use ($company, $catalog, &$created) {
            // Mapa: code => Account (incluye las ya existentes Y las creadas en esta corrida).
            $existing = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->pluck('id', 'code')
                ->all();

            foreach ($catalog as $entry) {
                $code = $entry['code'];

                if (isset($existing[$code])) {
                    continue;
                }

                $type = Account::typeFromCode($code);
                $level = Account::levelFromCode($code);
                $parentCode = Account::parentCodeOf($code);
                $parentId = $parentCode !== null ? ($existing[$parentCode] ?? null) : null;

                $account = Account::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'code' => $code,
                    'name' => $entry['name'],
                    'type' => $type,
                    'nature' => Account::TYPE_NATURE[$type],
                    'parent_id' => $parentId,
                    'level' => $level,
                    // Provisional: lo decide recomputeAcceptsMovements() al
                    // final, cuando ya se sabe quien tiene hijos.
                    'accepts_movements' => false,
                    'requires_third_party' => $entry['requires_third_party'] ?? false,
                    'requires_cost_center' => false,
                    'active' => true,
                    'is_system' => true,
                ]);

                $existing[$code] = $account->id;
                $created++;
            }

            // Abre las hojas para movimientos. Solo abre: ver el metodo.
            $this->recomputeAcceptsMovements($company);
        });

        return $created;
    }

    /**
     * Abre para movimientos las cuentas hoja de nivel cuenta (4 digitos) o
     * mas: se postea en la cuenta mas detallada que exista.
     *
     * SOLO abre, nunca cierra. Esta accion se puede volver a lanzar sobre una
     * empresa que ya opera, y cerrar cuentas que alguien habilito a proposito
     * —4135 como cuenta de venta, 1435 como inventario— deja sus productos y
     * sus asientos apuntando a cuentas inservibles. Ya paso una vez.
     *
     * Si una cuenta con hijos queda abierta, es una decision contable que se
     * corrige en el plan de cuentas, no algo que este proceso deba imponer.
     */
    private function recomputeAcceptsMovements(Company $company): void
    {
        $conHijos = function ($q) use ($company) {
            $q->from('accounts')
                ->where('company_id', $company->id)
                ->whereNotNull('parent_id')
                ->select('parent_id')
                ->distinct();
        };

        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('level', '>=', 3)
            ->where('accepts_movements', false)
            ->whereNotIn('id', $conHijos)
            ->update(['accepts_movements' => true]);
    }
}
