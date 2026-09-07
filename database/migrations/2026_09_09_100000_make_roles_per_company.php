<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los roles pasan a ser de cada empresa.
 *
 * Hasta ahora `roles` no tenia company_id: las seis filas eran GLOBALES y
 * todas las empresas veian y editaban las mismas. Dos consecuencias, y la
 * segunda es la grave:
 *
 *   - En la pantalla de roles se veian los de las demas empresas.
 *   - Si una empresa le quitaba un permiso al rol "cajero", se lo quitaba a
 *     los cajeros de TODAS. Asi es como un parqueadero se quedo sin
 *     `parking.use` y su cajero terminaba en el POS retail.
 *
 * El arreglo: `company_id` en roles, con NULL reservado para las plantillas
 * del sistema, que ya no se asignan a nadie y solo sirven de molde al crear
 * una empresa nueva.
 *
 * A cada empresa que ya tenia usuarios con rol se le clona el rol que usaba,
 * con sus permisos tal como estan hoy, y se le repunta la asignacion. Nadie
 * gana ni pierde acceso en la migracion: cada empresa se queda exactamente
 * con lo que tenia en el momento de correrla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        // El indice unico de Spatie es (name, guard_name). Con roles por
        // empresa, dos empresas tienen su propio "cajero" y ese indice lo
        // impide.
        $this->reemplazarIndiceUnico();

        $this->clonarRolesPorEmpresa();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        // Las asignaciones vuelven a la plantilla del mismo nombre.
        foreach (DB::table('roles')->whereNotNull('company_id')->get() as $rol) {
            $plantilla = DB::table('roles')
                ->whereNull('company_id')
                ->where('name', $rol->name)
                ->where('guard_name', $rol->guard_name)
                ->value('id');

            if ($plantilla) {
                DB::table('model_has_roles')->where('role_id', $rol->id)
                    ->update(['role_id' => $plantilla]);
            }
        }

        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->whereNotNull('company_id')->pluck('id'))
            ->delete();

        DB::table('roles')->whereNotNull('company_id')->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_company_id_name_guard_name_unique');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name)');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function reemplazarIndiceUnico(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_name_guard_name_unique');
        DB::statement(
            'ALTER TABLE roles ADD CONSTRAINT roles_company_id_name_guard_name_unique
             UNIQUE (company_id, name, guard_name)'
        );
    }

    /**
     * Cada empresa se queda con su propia copia del rol que sus usuarios ya
     * tenian, con los permisos que ese rol tiene hoy.
     */
    private function clonarRolesPorEmpresa(): void
    {
        $asignaciones = DB::table('model_has_roles as mhr')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', 'mhr.model_id')
                    ->where('mhr.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereNotNull('u.company_id')
            ->whereNull('r.company_id')
            ->select('u.company_id', 'r.id as role_id', 'r.name', 'r.guard_name')
            ->distinct()
            ->get();

        $clones = [];

        foreach ($asignaciones as $asignacion) {
            $clave = $asignacion->company_id.'|'.$asignacion->name.'|'.$asignacion->guard_name;

            if (! isset($clones[$clave])) {
                $clones[$clave] = $this->clonar($asignacion);
            }

            // Repunta a los usuarios de esa empresa hacia su propia copia.
            DB::table('model_has_roles')
                ->where('role_id', $asignacion->role_id)
                ->where('model_type', 'App\\Models\\User')
                ->whereIn('model_id', DB::table('users')
                    ->where('company_id', $asignacion->company_id)->pluck('id'))
                ->update(['role_id' => $clones[$clave]]);
        }
    }

    private function clonar(object $asignacion): int
    {
        $nuevoId = DB::table('roles')->insertGetId([
            'company_id' => $asignacion->company_id,
            'name' => $asignacion->name,
            'guard_name' => $asignacion->guard_name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permisos = DB::table('role_has_permissions')
            ->where('role_id', $asignacion->role_id)
            ->pluck('permission_id');

        foreach ($permisos as $permisoId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $nuevoId,
                'permission_id' => $permisoId,
            ]);
        }

        return $nuevoId;
    }
};
