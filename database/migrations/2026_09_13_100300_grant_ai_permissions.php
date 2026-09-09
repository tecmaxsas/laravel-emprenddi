<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Entrega los permisos nuevos de Claude a los roles que ya existen.
 *
 * Los roles de cada empresa son copias de las plantillas hechas en su momento,
 * así que un permiso agregado después no les llega solo. Sin esto, la sección
 * quedaría instalada y sin nadie que pudiera abrirla.
 *
 *   - `ai.use` va también a manager: consultar los datos del propio negocio es
 *     parte de administrarlo.
 *   - `ai.manage` solo a admin: toca la llave de Anthropic y el saldo.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'ai.use' => ['admin', 'manager'],
        'ai.manage' => ['admin'],
    ];

    public function up(): void
    {
        foreach (self::PERMISOS as $permiso => $roles) {
            $permisoId = DB::table('permissions')
                ->where('name', $permiso)->where('guard_name', 'web')->value('id')
                ?: DB::table('permissions')->insertGetId([
                    'name' => $permiso,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $rolIds = DB::table('roles')
                ->whereIn('name', $roles)
                ->where('guard_name', 'web')
                ->pluck('id');

            if ($rolIds->isEmpty()) {
                continue;
            }

            DB::table('role_has_permissions')->insertOrIgnore(
                $rolIds->map(fn ($id) => ['role_id' => $id, 'permission_id' => $permisoId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', array_keys(self::PERMISOS))
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
