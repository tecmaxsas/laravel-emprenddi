<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free-trial',
                'name' => 'Prueba Gratuita',
                'description' => 'Plan inicial de prueba — 30 días para evaluar Emprenddi.',
                'price' => 0,
                'billing_cycle' => 'monthly',
                'currency' => 'COP',
                'trial_days' => 30,
                'features' => ['electronic_billing'],
                'limits' => [
                    'max_locations' => 1,
                    'max_users' => 2,
                    'max_products' => 50,
                    'max_invoices_per_month' => 30,
                    'max_third_parties' => 50,
                    'max_storage_mb' => 256,
                ],
                'is_active' => true,
                'is_public' => false,
                'sort_order' => 0,
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'Para emprendedores y micro-empresas que están arrancando.',
                'price' => 49000,
                'billing_cycle' => 'monthly',
                'currency' => 'COP',
                'trial_days' => 14,
                'features' => ['electronic_billing', 'retail'],
                'limits' => [
                    'max_locations' => 1,
                    'max_users' => 3,
                    'max_products' => 500,
                    'max_invoices_per_month' => 200,
                    'max_third_parties' => 500,
                    'max_storage_mb' => 1024,
                ],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 10,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Para PYMES en crecimiento con múltiples sedes y usuarios.',
                'price' => 149000,
                'billing_cycle' => 'monthly',
                'currency' => 'COP',
                'trial_days' => 14,
                'features' => [
                    'electronic_billing', 'retail', 'restaurant',
                    'pharmacy', 'services', 'multi_currency',
                ],
                'limits' => [
                    'max_locations' => 5,
                    'max_users' => 15,
                    'max_products' => 5000,
                    'max_invoices_per_month' => 2000,
                    'max_third_parties' => 5000,
                    'max_storage_mb' => 10240,
                ],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Para empresas grandes con necesidades ilimitadas y soporte dedicado.',
                'price' => 499000,
                'billing_cycle' => 'monthly',
                'currency' => 'COP',
                'trial_days' => 0,
                'features' => array_keys(Plan::FEATURE_KEYS),
                'limits' => [
                    'max_locations' => null,
                    'max_users' => null,
                    'max_products' => null,
                    'max_invoices_per_month' => null,
                    'max_third_parties' => null,
                    'max_storage_mb' => null,
                ],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 30,
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
