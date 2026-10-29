<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Onboarding\CompanyOnboarding;
use App\Services\Parking\ParkingDefaultsProvisioner;
use Illuminate\Console\Command;

/**
 * Activa el modulo parqueadero para una empresa: agrega 'parking' a
 * companies.active_modules (idempotente) y siembra los tipos de vehiculo
 * por defecto. Reservado al super-admin operando la VM.
 *
 *   php artisan companies:enable-parking 5
 *   php artisan companies:enable-parking --nit=900123456
 */
class EnableParkingCommand extends Command
{
    protected $signature = 'companies:enable-parking
        {company? : ID de la empresa}
        {--nit= : NIT de la empresa (alternativa al ID)}';

    protected $description = 'Activa el módulo parqueadero para una empresa y siembra defaults';

    public function handle(ParkingDefaultsProvisioner $defaults, CompanyOnboarding $onboarding): int
    {
        $company = $this->resolveCompany();
        if (! $company) {
            $this->error('No encontré la empresa.');
            return self::FAILURE;
        }

        $this->info("Empresa: {$company->name} (#{$company->id})");

        $modules = $company->active_modules ?? [];
        if (! in_array('parking', $modules, true)) {
            $modules[] = 'parking';
            $company->update(['active_modules' => array_values(array_unique($modules))]);
            $this->info("✓ Módulo 'parking' agregado a active_modules.");
        } else {
            $this->line("  Módulo 'parking' ya estaba activo.");
        }

        // Asegura PUC, impuestos, sede principal, Consumidor Final, plantillas
        // (idempotente — solo crea lo que falte)
        $bootstrap = $onboarding->bootstrap($company);
        $this->info("✓ Bootstrap: PUC={$bootstrap['puc']}, impuestos={$bootstrap['taxes']}, sede={$bootstrap['location']}, plantillas={$bootstrap['invoice_templates']}.");

        $r = $defaults->provision($company);
        $this->info("✓ Tipos de vehículo: {$r['created']} creados, {$r['existing']} ya existían.");

        $this->newLine();
        $this->line("Próximos pasos manuales para el admin de la empresa:");
        $this->line("  1. Crear parqueadero(s) con sede de facturación");
        $this->line("  2. Crear tarifas (al menos una por tipo de vehículo)");
        $this->line("  3. (Opcional) Definir espacios y zonas");
        $this->line("  4. Asignar permisos del grupo 'Parqueadero' a los roles");

        return self::SUCCESS;
    }

    protected function resolveCompany(): ?Company
    {
        $id = $this->argument('company');
        if ($id) return Company::find($id);

        // `nit` y no `document_number`: esa columna no existe en
        // `companies`, asi que la opcion reventaba con «Undefined column» a
        // quien la usara.
        $nit = $this->option('nit');
        if ($nit) return Company::query()->where('nit', $nit)->first();

        $this->error('Debes pasar el ID como argumento o --nit=NIT.');
        return null;
    }
}
