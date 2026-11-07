<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\ModuleGate;

/**
 * Deja la contabilidad encendida o apagada durante una prueba, y la devuelve.
 *
 * POR QUE EXISTE. Varias pantallas cambian según el módulo: el formulario de
 * gastos pide la cuenta del PUC cuando la contabilidad está activa, y la
 * resuelve por debajo cuando no. Una prueba que no dice cuál de las dos cosas
 * espera **solo pasa cuando la base de desarrollo está configurada como ella
 * supone**, y deja de pasar en cuanto otra prueba —o alguien probando a
 * mano— cambia el módulo.
 *
 * Eso ya ocurrió: `ExpenseCategoriesTest` daba por hecho que la contabilidad
 * estaba apagada. El día que la empresa de pruebas quedó con el módulo
 * encendido, la prueba empezó a fallar con «expense_account_id es
 * obligatorio» — un mensaje que no tiene nada que ver con las categorías de
 * gasto, que es lo que estaba probando.
 *
 * Es el mismo remedio de `FuerzaPosDeRetail`, por la misma razón: una prueba
 * tiene que fijar el estado del que depende, no heredarlo.
 */
trait FuerzaContabilidad
{
    /**
     * Apaga la contabilidad mientras dure la prueba.
     *
     * @param  list<callable>  $limpiar  cola de restauración de la prueba
     */
    protected function apagaContabilidad(Company $company, array &$limpiar): void
    {
        $this->cambiaModulos(
            $company,
            $limpiar,
            array_values(array_diff($company->active_modules ?? [], [ModuleGate::ACCOUNTING])),
        );
    }

    /**
     * La enciende mientras dure la prueba.
     *
     * @param  list<callable>  $limpiar  cola de restauración de la prueba
     */
    protected function enciendeContabilidad(Company $company, array &$limpiar): void
    {
        $actuales = $company->active_modules ?? [];

        if (in_array(ModuleGate::ACCOUNTING, $actuales, true)) {
            return;
        }

        $this->cambiaModulos($company, $limpiar, [...$actuales, ModuleGate::ACCOUNTING]);
    }

    /**
     * @param  list<callable>  $limpiar
     * @param  list<string>  $modulos
     */
    private function cambiaModulos(Company $company, array &$limpiar, array $modulos): void
    {
        $original = $company->active_modules;

        if ($modulos === ($original ?? [])) {
            return;
        }

        $this->aplicaModulos($company, $modulos);

        $limpiar[] = function () use ($company, $original) {
            $this->aplicaModulos($company, $original);
        };
    }

    /** @param  list<string>|null  $modulos */
    private function aplicaModulos(Company $company, ?array $modulos): void
    {
        $company->update(['active_modules' => $modulos]);
        app(CurrentCompany::class)->set($company->fresh());
    }
}
