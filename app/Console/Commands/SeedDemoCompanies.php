<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Crea/actualiza las empresas demo (Retail, Restaurante y/o Sport) con
 * catalogo completo, clientes, proveedores y — segun el tipo — zonas/mesas
 * o multiples sedes. Idempotente — re-ejecutar no duplica.
 *
 * Uso:
 *   php artisan demo:seed retail
 *   php artisan demo:seed restaurant
 *   php artisan demo:seed sport
 *   php artisan demo:seed both    (retail + restaurant)
 *   php artisan demo:seed all     (las 3)
 *
 * Credenciales (fijas):
 *   demo-retail@emprenddi.com      / Demo2026!  (NIT 900111111-1)
 *   demo-restaurante@emprenddi.com / Demo2026!  (NIT 900222222-2)
 *   demo-sport@emprenddi.com       / Demo2026!  (NIT 900333333-3)
 */
class SeedDemoCompanies extends Command
{
    protected $signature = 'demo:seed
        {kind=both : Qué crear — retail | restaurant | sport | both | all}';

    protected $description = 'Crea empresas demo (Retail, Restaurante y/o Sport) con catálogo, clientes y configuración completa.';

    public function handle(DemoSeeder $seeder): int
    {
        $kind = $this->argument('kind');
        if (! in_array($kind, ['retail', 'restaurant', 'sport', 'both', 'all'], true)) {
            $this->error("kind invalido — usa 'retail', 'restaurant', 'sport', 'both' o 'all'");
            return self::FAILURE;
        }

        $reports = [];

        if ($kind === 'retail' || $kind === 'both' || $kind === 'all') {
            $this->info('→ Creando empresa demo RETAIL...');
            $reports['retail'] = $seeder->seedRetail();
            $this->info('  ✓ Retail listo.');
        }

        if ($kind === 'restaurant' || $kind === 'both' || $kind === 'all') {
            $this->info('→ Creando empresa demo RESTAURANTE...');
            $reports['restaurant'] = $seeder->seedRestaurant();
            $this->info('  ✓ Restaurante listo.');
        }

        if ($kind === 'sport' || $kind === 'all') {
            $this->info('→ Creando empresa demo SPORT...');
            $reports['sport'] = $seeder->seedSport();
            $this->info('  ✓ Sport listo.');
        }

        $this->newLine();
        $this->line('=========================================================');
        $this->line('  EMPRESAS DEMO LISTAS');
        $this->line('=========================================================');

        foreach ($reports as $name => $r) {
            $this->newLine();
            $this->line(sprintf('  [%s] %s (NIT %s-%s)',
                strtoupper($name),
                $r['company']->name,
                $r['company']->nit,
                $r['company']->dv,
            ));
            $this->line('  Login URL:  ' . $r['login_url']);
            $this->line('  Email:      ' . $r['admin_email']);
            $this->line('  Password:   ' . $r['admin_password']);
            $this->line(sprintf('  Datos:      %d categorías · %d productos · %d clientes · %d proveedores',
                $r['categories'], $r['products'], $r['clients'], $r['suppliers'],
            ));
            if (isset($r['tables'])) {
                $this->line(sprintf('  Restaurante: %d mesas · %d grupos de modificadores',
                    $r['tables'], $r['modifier_groups'],
                ));
            }
            if (isset($r['locations'])) {
                $this->line(sprintf('  Sedes:      %d (Bodega Central + sedes) · %d ventas demo cargadas',
                    $r['locations'], $r['history'] ?? 0,
                ));
            }
        }

        $this->newLine();
        $this->info('Listo. El comando es idempotente — puedes re-ejecutarlo para completar lo que falte.');

        return self::SUCCESS;
    }
}
