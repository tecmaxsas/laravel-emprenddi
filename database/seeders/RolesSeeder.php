<?php

namespace Database\Seeders;

use App\Services\Auth\PermissionsCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Permisos canónicos del catálogo
        foreach (PermissionsCatalog::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. Roles base + asignación de permisos default
        $roles = ['admin', 'manager', 'accountant', 'cashier', 'seller', 'accountant_external'];

        foreach ($roles as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            $defaultPermissions = PermissionsCatalog::defaultForRole($name);

            if ($role->wasRecentlyCreated) {
                // Rol recien creado: asignar todo el set default.
                $role->syncPermissions($defaultPermissions);
            } else {
                // Rol ya existia: NO pisar permisos custom del admin.
                // Solo agregar los permisos default que el rol todavia no tiene.
                // Esto resuelve el problema de que un deploy nuevo (con permisos
                // nuevos en el catalog) revierta personalizaciones manuales.
                $existing = $role->permissions->pluck('name')->all();
                $toAdd = array_diff($defaultPermissions, $existing);
                if (! empty($toAdd)) {
                    $role->givePermissionTo($toAdd);
                }
                // Para 'admin' especificamente: garantizar que tenga TODO,
                // porque admin debe siempre tener acceso completo aunque
                // alguien lo haya restringido por error.
                if ($name === 'admin') {
                    $role->syncPermissions(PermissionsCatalog::all());
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
