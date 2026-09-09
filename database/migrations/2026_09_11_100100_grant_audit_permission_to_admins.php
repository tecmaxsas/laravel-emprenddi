<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Le entrega el permiso nuevo `audit.view` a los administradores que ya existen.
 *
 * El RolesSeeder solo mantiene las plantillas (`company_id` nulo). Los roles de
 * cada empresa son copias hechas en su momento, así que un permiso agregado
 * después no les llega solo: sin esto, la pantalla de auditoría quedaría
 * instalada y sin nadie que pudiera abrirla.
 *
 * Solo a `admin`, que es lo que se pidió: la bitácora muestra los movimientos
 * de todos, incluido el de quien la consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permisoId = DB::table('permissions')
            ->where('name', 'audit.view')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permisoId) {
            $permisoId = DB::table('permissions')->insertGetId([
                'name' => 'audit.view',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $adminIds = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($adminIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->insertOrIgnore(
            $adminIds->map(fn ($id) => [
                'role_id' => $id,
                'permission_id' => $permisoId,
            ])->all()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permisoId = DB::table('permissions')
            ->where('name', 'audit.view')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permisoId) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
        DB::table('permissions')->where('id', $permisoId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
