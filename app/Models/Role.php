<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol de una empresa.
 *
 * Spatie da por hecho que el nombre de un rol es unico en toda la instalacion,
 * y aqui no: cada empresa tiene su propio "cajero". Si no se ajustara, crear
 * el segundo reventaria con RoleAlreadyExists y buscar uno por nombre podria
 * devolver el de otra compañia.
 *
 * Los roles con `company_id` nulo son las plantillas del sistema: el molde con
 * el que arranca cada empresa, que no se le asigna a nadie.
 */
class Role extends SpatieRole
{
    protected $guarded = ['id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Un rol se repite entre empresas: lo que no puede repetirse es el nombre
     * DENTRO de una. Spatie compara solo por nombre y guard, asi que la
     * segunda empresa que creara su "cajero" reventaba con RoleAlreadyExists.
     */
    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);
        $companyId = $attributes['company_id'] ?? null;

        $yaExiste = static::query()
            ->where('name', $attributes['name'])
            ->where('guard_name', $attributes['guard_name'])
            ->where(fn ($q) => $companyId === null
                ? $q->whereNull('company_id')
                : $q->where('company_id', $companyId))
            ->exists();

        if ($yaExiste) {
            throw RoleAlreadyExists::create(
                $attributes['name'],
                $attributes['guard_name'],
            );
        }

        return static::query()->create($attributes);
    }

    /**
     * Resolver un rol por nombre: primero el de la empresa del usuario y, si
     * no lo tiene, la plantilla. Ese orden importa — un usuario tiene que
     * resolver SU rol, no el molde ni el de otra compañia.
     */
    protected static function findByParam(array $params = []): ?\Spatie\Permission\Contracts\Role
    {
        $companyId = $params['company_id'] ?? auth()->user()?->company_id;
        unset($params['company_id']);

        $query = static::query();

        foreach ($params as $key => $value) {
            $query->where($key, $value);
        }

        return (clone $query)->where('company_id', $companyId)->first()
            ?? $query->whereNull('company_id')->first();
    }
}
