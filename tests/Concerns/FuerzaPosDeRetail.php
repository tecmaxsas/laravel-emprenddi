<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;

/**
 * Deja la empresa en condiciones de abrir el POS de retail.
 *
 * `PosTerminal::mount()` redirige si la empresa tiene restaurante o
 * parqueadero: ahí el flujo de venta es otro y el POS correcto es ese. Una
 * prueba que monta el POS de retail sin asegurar esto solo pasa cuando la base
 * de desarrollo está configurada como esa prueba espera —y deja de pasar en
 * cuanto otra prueba, o alguien probando a mano, activa un módulo—.
 *
 * Eso ya ocurrió: la empresa quedó con solo `parking` activo y ocho pruebas del
 * POS empezaron a fallar con «Invalid Livewire snapshot structure», que es como
 * se ve un redirect desde una prueba de Livewire. Nada que ver con el código
 * que estaban probando.
 *
 * Vive aquí y no copiado en cada prueba porque ya hacen falta dos.
 */
trait FuerzaPosDeRetail
{
    /**
     * Apaga los módulos que desvían el POS, y los devuelve al terminar.
     *
     * @param  list<callable>  $limpiar  cola de restauración de la prueba
     */
    protected function fuerzaPosDeRetail(Company $company, array &$limpiar): void
    {
        $original = $company->active_modules;

        $sinOtrosPos = array_values(array_diff($original ?? [], ['restaurant', 'parking']));

        if ($sinOtrosPos === ($original ?? [])) {
            return;
        }

        $this->aplicarModulos($company, $sinOtrosPos);

        $limpiar[] = function () use ($company, $original) {
            $this->aplicarModulos($company, $original);
        };
    }

    /** @param  list<string>|null  $modulos */
    private function aplicarModulos(Company $company, ?array $modulos): void
    {
        $company->update(['active_modules' => $modulos]);
        app(CurrentCompany::class)->set($company->fresh());

        // El usuario de `actingAs` sobrevive entre peticiones de la misma
        // prueba, asi que su relacion `company` se queda con los modulos
        // viejos y el redirect seguiria pasando.
        auth()->user()?->unsetRelation('company');
    }
}
