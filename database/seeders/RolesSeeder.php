<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            'admin' => 'Administrador de la empresa',
            'manager' => 'Gerente / Supervisor',
            'accountant' => 'Contador',
            'cashier' => 'Cajero',
            'seller' => 'Vendedor',
        ];

        foreach ($roles as $name => $description) {
            Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
