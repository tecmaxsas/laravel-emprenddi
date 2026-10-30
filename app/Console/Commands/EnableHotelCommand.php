<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Hotel\Hotel;
use App\Services\Hotel\HotelDefaultsProvisioner;
use App\Services\Hotel\HotelProductProvisioner;
use App\Services\Onboarding\CompanyOnboarding;
use Illuminate\Console\Command;

/**
 * Activa el módulo Hoteles para una empresa.
 *
 * Idempotente: agrega 'hotel' a companies.active_modules, asegura el
 * bootstrap contable, crea un hotel inicial si no hay ninguno y siembra los
 * tipos de habitación. Reservado al super-admin operando la VM.
 *
 *   php artisan companies:enable-hotel 5
 *   php artisan companies:enable-hotel --nit=900123456
 */
class EnableHotelCommand extends Command
{
    protected $signature = 'companies:enable-hotel
        {company? : ID de la empresa}
        {--nit= : NIT de la empresa (alternativa al ID)}
        {--nombre= : Nombre del hotel inicial, si hay que crearlo}';

    protected $description = 'Activa el módulo Hoteles para una empresa y siembra lo mínimo para operar';

    public function handle(
        HotelDefaultsProvisioner $defaults,
        HotelProductProvisioner $productos,
        CompanyOnboarding $onboarding,
    ): int {
        $company = $this->resolverEmpresa();

        if (! $company) {
            $this->error('No encontré la empresa.');

            return self::FAILURE;
        }

        $this->info("Empresa: {$company->name} (#{$company->id})");

        $modulos = $company->active_modules ?? [];

        if (! in_array('hotel', $modulos, true)) {
            $modulos[] = 'hotel';
            $company->update(['active_modules' => array_values(array_unique($modulos))]);
            $this->info("✓ Módulo 'hotel' agregado a active_modules.");
        } else {
            $this->line("  Módulo 'hotel' ya estaba activo.");
        }

        // PUC, impuestos, sede principal, Consumidor Final, plantillas.
        $bootstrap = $onboarding->bootstrap($company);
        $this->info(
            "✓ Bootstrap: PUC={$bootstrap['puc']}, impuestos={$bootstrap['taxes']}, "
            ."sede={$bootstrap['location']}, plantillas={$bootstrap['invoice_templates']}."
        );

        $hotel = Hotel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first();

        if (! $hotel) {
            $sede = $company->locations()->where('active', true)->orderByDesc('is_main')->first();

            $hotel = Hotel::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'code' => 'H01',
                'name' => $this->option('nombre') ?: $company->name,
                'city' => $company->city,
                'address' => $company->address,
                'phone' => $company->phone,
                'default_location_id' => $sede?->id,
                'default_invoice_kind' => 'pos',
                'active' => true,
            ]);

            $this->info("✓ Hotel «{$hotel->name}» creado (código {$hotel->code}).");
        } else {
            $this->line("  Hotel «{$hotel->name}» ya existía.");
        }

        $tipos = $defaults->provision($company, $hotel);
        $this->info("✓ Tipos de habitación: {$tipos['creados']} creados, {$tipos['existentes']} ya existían.");
        $this->info("✓ Planes de alimentación: {$tipos['planes']} sembrados.");

        $producto = $productos->ensure($company);
        $this->info("✓ Producto de alojamiento: {$producto->code} — {$producto->name}.");

        $this->newLine();
        $this->line('Lo que falta y tiene que hacer el administrador de la empresa:');
        $this->line('  1. Ponerle tarifa a cada tipo de habitación (quedan en $0 y así no se puede alojar a nadie)');
        $this->line('  2. Crear las zonas (pisos, torres) y las habitaciones');
        $this->line('  3. Verificar la sede de facturación del hotel');
        $this->line("  4. Asignar los permisos del grupo 'Hotel' a los roles que correspondan");

        return self::SUCCESS;
    }

    protected function resolverEmpresa(): ?Company
    {
        $id = $this->argument('company');

        if ($id) {
            return Company::find($id);
        }

        // `nit` y no `document_number`: esa columna no existe en `companies`.
        // La opción venía copiada de EnableParkingCommand, donde apuntaba a
        // una columna inexistente y reventaba con «Undefined column» a quien
        // la usara.
        $nit = $this->option('nit');

        if ($nit) {
            return Company::query()->where('nit', $nit)->first();
        }

        $this->error('Debes pasar el ID como argumento o --nit=NIT.');

        return null;
    }
}
