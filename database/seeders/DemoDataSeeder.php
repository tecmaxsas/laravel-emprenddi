<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Superadmin (no pertenece a ninguna empresa)
        User::firstOrCreate(
            ['email' => env('FILAMENT_SUPERADMIN_EMAIL', 'superadmin@emprenddi.com')],
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
                'document_type' => 'nit',
                'organization_type' => 'juridica',
                'dv' => '7',
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => 'Calle 100 #15-20',
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '6011234567',
                'phone_country_code' => '+57',
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
                'name' => 'Admin',
                'last_name' => 'Demo',
                'password' => Hash::make('password'),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole('admin');
    }
}
