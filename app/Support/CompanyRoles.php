<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de una empresa.
 *
 * Los roles con `company_id` nulo son PLANTILLAS del sistema: definen como
 * arranca cada rol, pero no se le asignan a nadie. Cada empresa trabaja sobre
 * su propia copia, para que editarle un permiso al cajero de una no se lo
 * quite a los cajeros de las demas.
 *
 * Por eso `assignRole('admin')` a secas ya no sirve: con roles por empresa el
 * nombre esta repetido y Spatie tomaria el primero que encuentre, que puede
 * ser el de otra compañia. Todo el codigo que asigna roles pasa por aqui.
 */
class CompanyRoles
{
    /**
     * El rol de esa empresa con ese nombre. Si no lo tiene, se le crea a
     * partir de la plantilla.
     */
    public static function resolve(int $companyId, string $name, string $guard = 'web'): ?Role
    {
        $rol = Role::query()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->first();

        if ($rol) {
            return $rol;
        }

        return self::cloneTemplate($companyId, $name, $guard);
    }

    /** Asigna a un usuario el rol de SU empresa, no el de otra. */
    public static function assign(User $user, string $name): ?Role
    {
        if (! $user->company_id) {
            return null;
        }

        $rol = self::resolve((int) $user->company_id, $name);

        if ($rol) {
            $user->assignRole($rol);
        }

        return $rol;
    }

    /**
     * Le da a una empresa su juego de roles, copiando las plantillas.
     *
     * Idempotente: los que ya tenga se dejan como estan, incluidas las
     * personalizaciones que el administrador le haya hecho.
     *
     * @return int cuantos se crearon
     */
    public static function provision(Company $company): int
    {
        $creados = 0;

        foreach (self::templates() as $plantilla) {
            $existe = Role::query()
                ->where('company_id', $company->id)
                ->where('name', $plantilla->name)
                ->where('guard_name', $plantilla->guard_name)
                ->exists();

            if ($existe) {
                continue;
            }

            self::copiar($plantilla, (int) $company->id);
            $creados++;
        }

        if ($creados > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $creados;
    }

    /** @return Collection<int, Role> */
    public static function templates()
    {
        return Role::query()->whereNull('company_id')->orderBy('id')->get();
    }

    private static function cloneTemplate(int $companyId, string $name, string $guard): ?Role
    {
        $plantilla = Role::query()
            ->whereNull('company_id')
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->first();

        if (! $plantilla) {
            return null;
        }

        $rol = self::copiar($plantilla, $companyId);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $rol;
    }

    private static function copiar(Role $plantilla, int $companyId): Role
    {
        $rol = Role::create([
            'company_id' => $companyId,
            'name' => $plantilla->name,
            'guard_name' => $plantilla->guard_name,
        ]);

        // Se copian por id y no con syncPermissions() para no cargar los
        // modelos: al aprovisionar una empresa nueva son seis roles con
        // decenas de permisos cada uno.
        $filas = DB::table('role_has_permissions')
            ->where('role_id', $plantilla->id)
            ->pluck('permission_id')
            ->map(fn ($id) => ['role_id' => $rol->id, 'permission_id' => $id])
            ->all();

        if ($filas !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($filas);
        }

        return $rol;
    }
}
