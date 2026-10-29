<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\PosDestination;

/**
 * Deja la empresa en condiciones de abrir el POS de retail.
 *
 * `PosTerminal::mount()` redirige si la empresa tiene un módulo vertical
 * activo —restaurante, parqueadero, hotel—: ahí el flujo de venta es otro y el
 * POS correcto es ese. Una prueba que monta el POS de retail sin asegurar esto
 * solo pasa cuando la base de desarrollo está configurada como esa prueba
 * espera —y deja de pasar en cuanto otra prueba, o alguien probando a mano,
 * activa un módulo—.
 *
 * Eso ya ocurrió dos veces. La primera, la empresa quedó con solo `parking`
 * activo y ocho pruebas del POS empezaron a fallar con «Invalid Livewire
 * snapshot structure», que es como se ve un redirect desde una prueba de
 * Livewire. Nada que ver con el código que estaban probando.
 *
 * La segunda fue peor, porque fue culpa de esta misma lista: al nacer el
 * módulo de hoteles este trait siguió apagando solo restaurante y parqueadero,
 * y doce pruebas del POS cayeron igual. Por eso **la lista ya no se escribe
 * aquí**: sale de `PosDestination::MODULOS_QUE_DESVIAN`, que es la misma que
 * usa el código que decide el redirect. Un destino nuevo queda cubierto solo.
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

        $sinOtrosPos = array_values(array_diff(
            $original ?? [],
            array_keys(PosDestination::MODULOS_QUE_DESVIAN),
        ));

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
