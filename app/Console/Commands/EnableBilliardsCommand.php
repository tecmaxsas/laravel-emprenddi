<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Billiards\BilliardDefaultsProvisioner;
use App\Services\Onboarding\CompanyOnboarding;
use Illuminate\Console\Command;

/**
 * Activa el modulo de billares para una empresa: agrega 'billiards' a
 * companies.active_modules (idempotente) y siembra los tipos de mesa.
 *
 *   php artisan companies:enable-billiards 5
 *   php artisan companies:enable-billiards --nit=900123456
 */
class EnableBilliardsCommand extends Command
{
    protected $signature = 'companies:enable-billiards
        {company? : ID de la empresa}
        {--nit= : NIT de la empresa (alternativa al ID)}';

    protected $description = 'Activa el módulo de billares para una empresa y siembra los tipos de mesa';

    public function handle(BilliardDefaultsProvisioner $defaults, CompanyOnboarding $onboarding): int
    {
        $company = $this->resolveCompany();

        if (! $company) {
            $this->error('No encontré la empresa.');

            return self::FAILURE;
        }

        $this->info("Empresa: {$company->name} (#{$company->id})");

        $modules = $company->active_modules ?? [];

        if (! in_array('billiards', $modules, true)) {
            $modules[] = 'billiards';
            $company->update(['active_modules' => array_values(array_unique($modules))]);
            $this->info("✓ Módulo 'billiards' agregado a active_modules.");
        } else {
            $this->line("  Módulo 'billiards' ya estaba activo.");
        }

        // PUC, impuestos, sede principal, Consumidor Final, plantillas.
        // Idempotente: solo crea lo que falte.
        $bootstrap = $onboarding->bootstrap($company);
        $this->info("✓ Bootstrap: PUC={$bootstrap['puc']}, impuestos={$bootstrap['taxes']}, "
            ."sede={$bootstrap['location']}, plantillas={$bootstrap['invoice_templates']}.");

        $r = $defaults->provision($company);
        $this->info("✓ Tipos de mesa: {$r['created']} creados, {$r['existing']} ya existían.");

        $this->newLine();
        $this->line('Próximos pasos para el admin de la empresa:');
        $this->line('  1. Crear la sala con su sede de facturación');
        $this->line('  2. Crear las mesas (por zona: salón, terraza, segundo piso)');
        $this->line('  3. Crear al menos una tarifa por tipo de mesa');
        $this->line("  4. Asignar los permisos del grupo 'Billares' a los roles");

        return self::SUCCESS;
    }

    protected function resolveCompany(): ?Company
    {
        $id = $this->argument('company');

        if ($id) {
            return Company::find($id);
        }

        $nit = $this->option('nit');

        if ($nit) {
            return Company::query()->where('nit', $nit)->first();
        }

        $this->error('Debes pasar el ID como argumento o --nit=NIT.');

        return null;
    }
}
