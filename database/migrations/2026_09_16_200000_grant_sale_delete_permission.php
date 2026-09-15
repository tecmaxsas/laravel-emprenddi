<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Entrega `sales.delete` a los administradores que ya existen.
 *
 * Solo a admin. Borrar una venta devuelve inventario, reversa asientos y
 * deshace pagos: no es una operación de mostrador, es una corrección que
 * alguien tiene que poder explicar. El rol manager queda fuera a propósito.
 *
 * Los roles de cada empresa son copias de las plantillas, así que un permiso
 * nuevo no les llega solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permisoId = DB::table('permissions')
            ->where('name', 'sales.delete')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => 'sales.delete',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $adminIds = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($adminIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->insertOrIgnore(
            $adminIds->map(fn ($id) => ['role_id' => $id, 'permission_id' => $permisoId])->all()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permisoId = DB::table('permissions')
            ->where('name', 'sales.delete')->where('guard_name', 'web')->value('id');

        if (! $permisoId) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
        DB::table('permissions')->where('id', $permisoId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
