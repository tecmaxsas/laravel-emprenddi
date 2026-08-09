<?php

namespace App\Models\Scopes;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra todo modelo multiempresa por la empresa de la request.
 *
 * Antes solo miraba si CurrentCompany estaba seteado y, si no lo estaba, se
 * iba sin filtrar (fail-open). Como el middleware que lo setea no corre en
 * las peticiones de Livewire, las tablas de Filament terminaban listando los
 * registros de todas las empresas. Ahora la decision la toma
 * CurrentCompany::scopeId(), que resuelve un fallback desde el usuario
 * autenticado y solo devuelve null en contextos deliberadamente
 * cross-tenant (consola, colas, super admin).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CurrentCompany::class)->scopeId();

        if ($companyId === null) {
            return;
        }

        // 0 = hay usuario pero no se le pudo resolver empresa. Fail-closed:
        // mejor una lista vacia que datos de otra empresa.
        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
