<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos propios para el modulo de gastos.
 *
 * Gastos colgaba de los permisos de compras: no se podia dar acceso a los
 * gastos sin dar tambien las facturas de compra, y en la pantalla de roles no
 * aparecia la seccion porque no existia.
 *
 * Al separarlos hay que traer a la gente que ya tenia acceso, o el cambio se
 * lleva por delante a los roles que ya estaban trabajando:
 *
 *   purchases.view   -> expenses.view
 *   purchases.create -> expenses.create
 *   purchases.post   -> expenses.post y expenses.cancel
 *
 * OJO con la ultima linea: contabilizar y anular un gasto no pedian NINGUN
 * permiso, bastaba con poder verlo. Ahora lo piden. Un rol que solo veia
 * compras deja de poder contabilizar gastos — eso era un hueco, no una
 * funcion, y se cierra a proposito.
 */
return new class extends Migration
{
    private const MAPEO = [
        'purchases.view' => ['expenses.view'],
        'purchases.create' => ['expenses.create'],
        'purchases.post' => ['expenses.post', 'expenses.cancel'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $nuevos = [];

        foreach (self::MAPEO as $equivalentes) {
            foreach ($equivalentes as $permiso) {
                $nuevos[$permiso] = $this->ensurePermission($permiso);
            }
        }

        foreach (self::MAPEO as $viejo => $equivalentes) {
            $idViejo = DB::table('permissions')
                ->where('name', $viejo)->where('guard_name', 'web')->value('id');

            if (! $idViejo) {
                continue;
            }

            $roles = DB::table('role_has_permissions')
                ->where('permission_id', $idViejo)
                ->pluck('role_id');

            foreach ($roles as $roleId) {
                foreach ($equivalentes as $permiso) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $nuevos[$permiso],
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        // Spatie cachea los permisos: sin esto el cambio no se ve hasta el
        // siguiente despliegue.
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('name', ['expenses.view', 'expenses.create', 'expenses.post', 'expenses.cancel'])
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function ensurePermission(string $nombre): int
    {
        $id = DB::table('permissions')
            ->where('name', $nombre)->where('guard_name', 'web')->value('id');

        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $nombre,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
