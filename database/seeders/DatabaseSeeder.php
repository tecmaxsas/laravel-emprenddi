<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles base (sin scope por empresa por ahora)
        foreach (['admin', 'manager', 'accountant', 'cashier', 'seller'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Superadmin (no pertenece a ninguna empresa)
        User::firstOrCreate(
            ['email' => config('app.superadmin_email', env('FILAMENT_SUPERADMIN_EMAIL', 'superadmin@emprenddi.com'))],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_admin' => true,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Empresa demo
        $company = Company::firstOrCreate(
            ['nit' => '900123456'],
            [
                'name' => 'Empresa Demo S.A.S.',
                'legal_name' => 'Empresa Demo Sociedad por Acciones Simplificada',
                'dv' => '7',
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => 'Calle 100 #15-20',
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '+57 601 1234567',
                'email' => 'contacto@empresademo.co',
                'currency' => 'COP',
                'timezone' => 'America/Bogota',
                'active_modules' => ['electronic_billing', 'restaurant'],
                'active' => true,
            ],
        );

        // Usuario admin de la empresa demo
        $admin = User::firstOrCreate(
            ['email' => 'admin@empresademo.co'],
            [
                'company_id' => $company->id,
                'name' => 'Admin Demo',
                'password' => Hash::make('password'),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole('admin');
    }
}
