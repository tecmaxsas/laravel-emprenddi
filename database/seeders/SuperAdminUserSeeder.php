<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('FILAMENT_SUPERADMIN_EMAIL');
        $initialPassword = env('FILAMENT_SUPERADMIN_INITIAL_PASSWORD');

        if (! $email) {
            $this->command->warn('FILAMENT_SUPERADMIN_EMAIL no está definido — saltando creación de superadmin.');

            return;
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            // Garantiza que es realmente superadmin y está activo (no toca password).
            if (! $existing->is_super_admin || ! $existing->active) {
                $existing->update([
                    'is_super_admin' => true,
                    'active' => true,
                    'company_id' => null,
                ]);
                $this->command->info("SuperAdmin existente actualizado: {$email}");
            }

            return;
        }

        if (! $initialPassword) {
            $this->command->warn(
                "Usuario superadmin {$email} no existe y FILAMENT_SUPERADMIN_INITIAL_PASSWORD no está definido. ".
                'Defínelo en .env y vuelve a correr el seeder.'
            );

            return;
        }

        User::create([
            'name' => 'Super',
            'last_name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($initialPassword),
            'is_super_admin' => true,
            'active' => true,
            'company_id' => null,
            'email_verified_at' => now(),
        ]);

        $this->command->info("SuperAdmin creado: {$email}");
        $this->command->warn('Cambia el password después del primer login y elimina FILAMENT_SUPERADMIN_INITIAL_PASSWORD del .env.');
    }
}
