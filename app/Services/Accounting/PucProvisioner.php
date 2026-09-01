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

            // Recomputa accepts_movements: una cuenta con hijos no puede recibir movimientos directos.
            $this->recomputeAcceptsMovements($company);
        });

        return $created;
    }

    /**
     * Recibe movimientos la cuenta que NO tiene hijos, de nivel cuenta (4
     * digitos) hacia abajo.
     *
     * Antes la regla era "solo las de 6 digitos", pero el catalogo trae
     * muchas cuentas de 4 sin subcuentas debajo: quedaban 126 ramas muertas
     * —3705 Utilidades acumuladas entre ellas— que no se podian usar ni
     * tenian hijo que si. El propio sistema las daba por utilizables: el
     * asiento de apertura de inventario busca la 3705.
     *
     * Se postea en la cuenta mas detallada que exista. Si mañana alguien crea
     * subcuentas bajo una de estas, esta misma regla la apaga sola.
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
            ->whereIn('id', $conHijos)
            ->update(['accepts_movements' => false]);

        Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('level', '>=', 3)
            ->whereNotIn('id', $conHijos)
            ->update(['accepts_movements' => true]);
    }
}
