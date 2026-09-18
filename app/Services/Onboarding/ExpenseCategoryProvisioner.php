<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;

/**
 * Le deja a la empresa nueva sus categorías de gasto.
 *
 * Sin esto el desplegable arranca vacío y el primer gasto obliga a interrumpir
 * lo que se estaba haciendo para ir a crear una categoría. Son las mismas que
 * recibieron las empresas que ya existían, para que los reportes de dos
 * empresas se puedan comparar.
 *
 * Idempotente: `firstOrCreate` por (empresa, nombre), así que puede volver a
 * correrse sobre una empresa que ya las tiene sin pisar lo que el usuario haya
 * cambiado.
 */
class ExpenseCategoryProvisioner
{
    public function provision(Company $company): int
    {
        $creadas = 0;

        DB::transaction(function () use ($company, &$creadas) {
            foreach (ExpenseCategory::INICIALES as $orden => [$nombre, $descripcion]) {
                $categoria = ExpenseCategory::withoutGlobalScopes()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'name' => $nombre,
                    ],
                    [
                        'description' => $descripcion,
                        'sort_order' => ($orden + 1) * 10,
                        'active' => true,
                    ],
                );

                if ($categoria->wasRecentlyCreated) {
                    $creadas++;
                }
            }
        });

        return $creadas;
    }
}
