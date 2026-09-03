<?php

namespace Database\Seeders;

use Database\Seeders\Dian\DepartmentsSeeder;
use Database\Seeders\Dian\DocumentTypesSeeder;
use Database\Seeders\Dian\MunicipalitiesSeeder;
use Database\Seeders\Dian\OrganizationTypesSeeder;
use Database\Seeders\Dian\PaymentFormsSeeder;
use Database\Seeders\Dian\PaymentMethodsSeeder;
use Database\Seeders\Dian\PayrollCatalogsSeeder;
use Database\Seeders\Dian\RegimeTypesSeeder;
use Database\Seeders\Dian\TaxLiabilitiesSeeder;
use Illuminate\Database\Seeder;

/**
 * Orquesta los 8 seeders de catálogos DIAN. Orden importa:
 *   - Departments antes de Municipalities (FK)
 *   - El resto son independientes
 *
 * Idempotente: cada seeder usa updateOrCreate por id.
 * Ejecutar en cada deploy via deploy.sh.
 */
class DianCatalogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentsSeeder::class,
            MunicipalitiesSeeder::class,
            DocumentTypesSeeder::class,
            OrganizationTypesSeeder::class,
            RegimeTypesSeeder::class,
            TaxLiabilitiesSeeder::class,
            PaymentFormsSeeder::class,
            PaymentMethodsSeeder::class,
            PayrollCatalogsSeeder::class,
        ]);
    }
}
