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

            $permissions = PermissionsCatalog::defaultForRole($name);
            // syncPermissions reemplaza el set actual — idempotente.
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
