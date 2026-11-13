<?php

use App\Services\Auth\PermissionsCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Une los roles CAJERO y `cashier` en uno solo: CAJERO.
 *
 * La plantilla `cashier` se renombro a CAJERO a mano y el seeder creo otra
 * `cashier`; desde entonces cada empresa tenia las dos (ver
 * PermissionsCatalog::CAJERO). En cada empresa, y en las plantillas:
 *
 *  - si tiene las dos, CAJERO se queda con la union de permisos —nadie
 *    pierde nada—, los usuarios de `cashier` pasan a CAJERO y `cashier` se
 *    borra;
 *  - si solo tiene `cashier`, se renombra a CAJERO y recibe lo que la
 *    plantilla CAJERO trae de mas.
 *
 * Los roles que los administradores crearon a mano («Cajero», «CAJERO
 * GENERAL»...) no se tocan.
 */
return new class extends Migration
{
    private const EXTRAS_CAJERO = [
        'payment_methods.view', 'printers.manage', 'purchases.pay',
        'sales.send_dian', 'delivery_notes.bill', 'quotations.convert',
    ];

    public function up(): void
    {
        $viejos = DB::table('roles')
            ->whereIn('name', PermissionsCatalog::ALIAS_CAJERO)
            ->where('guard_name', 'web')
            ->get();

        foreach ($viejos as $viejo) {
            DB::transaction(function () use ($viejo) {
                $cajero = DB::table('roles')
                    ->where('name', PermissionsCatalog::CAJERO)
                    ->where('guard_name', $viejo->guard_name)
                    ->where(fn ($q) => $viejo->company_id === null
                        ? $q->whereNull('company_id')
                        : $q->where('company_id', $viejo->company_id))
                    ->first();

                if (! $cajero) {
                    DB::table('roles')->where('id', $viejo->id)->update([
                        'name' => PermissionsCatalog::CAJERO,
                        'updated_at' => now(),
                    ]);

                    $extras = DB::table('permissions')
                        ->where('guard_name', $viejo->guard_name)
                        ->whereIn('name', self::EXTRAS_CAJERO)
                        ->pluck('id')
                        ->map(fn ($p) => ['role_id' => $viejo->id, 'permission_id' => $p])
                        ->all();
                    DB::table('role_has_permissions')->insertOrIgnore($extras);

                    return;
                }

                $permisos = DB::table('role_has_permissions')
                    ->where('role_id', $viejo->id)
                    ->pluck('permission_id')
                    ->map(fn ($p) => ['role_id' => $cajero->id, 'permission_id' => $p])
                    ->all();
                DB::table('role_has_permissions')->insertOrIgnore($permisos);

                $usuarios = DB::table('model_has_roles')
                    ->where('role_id', $viejo->id)
                    ->get()
                    ->map(fn ($f) => [
                        'role_id' => $cajero->id,
                        'model_type' => $f->model_type,
                        'model_id' => $f->model_id,
                    ])
                    ->all();
                DB::table('model_has_roles')->insertOrIgnore($usuarios);

                DB::table('model_has_roles')->where('role_id', $viejo->id)->delete();
                DB::table('role_has_permissions')->where('role_id', $viejo->id)->delete();
                DB::table('roles')->where('id', $viejo->id)->delete();
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** No se puede deshacer: los dos roles eran redundantes. */
    public function down(): void {}
};
