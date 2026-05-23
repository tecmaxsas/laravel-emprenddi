<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Onboarding\CompanyOnboarding;
use Illuminate\Console\Command;

/**
 * Retro-aplica el onboarding (CompanyOnboarding::bootstrap) a empresas
 * existentes. Útil cuando se añade un nuevo provisioner y se quiere
 * que todas las empresas se beneficien sin esperar a que un humano
 * abra cada una.
 *
 * Todos los provisioners son idempotentes — no pisa lo que el usuario
 * ya tenga creado, solo agrega lo faltante.
 *
 * Uso:
 *   php artisan companies:bootstrap           → todas las empresas activas
 *   php artisan companies:bootstrap --id=5    → solo la empresa con id=5
 *   php artisan companies:bootstrap --all     → incluye inactivas
 */
class BootstrapCompanies extends Command
{
    protected $signature = 'companies:bootstrap
        {--id= : ID de una sola empresa a procesar}
        {--all : Incluir empresas inactivas (por defecto solo active=true)}';

    protected $description = 'Re-ejecuta el onboarding (PUC, impuestos, monedas, métodos de pago, sede, Consumidor Final, plantillas) sobre empresas existentes. Idempotente.';

    public function handle(CompanyOnboarding $onboarding): int
    {
        $query = Company::withoutGlobalScopes();
        if ($id = $this->option('id')) {
            $query->whereKey((int) $id);
        } elseif (! $this->option('all')) {
            $query->where('active', true);
        }

        $companies = $query->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->warn('No hay empresas que procesar.');
            return self::SUCCESS;
        }

        $this->info("Procesando {$companies->count()} empresa(s)...");
        $rows = [];

        foreach ($companies as $company) {
            $this->line("→ [{$company->id}] {$company->name}");
            try {
                $summary = $onboarding->bootstrap($company);
                $rows[] = [
                    $company->id,
                    \Illuminate\Support\Str::limit($company->name, 30),
                    $summary['puc'],
                    $summary['taxes'],
                    $summary['currencies'],
                    $summary['payment_methods'],
                    $summary['location'],
                    $summary['consumer_final'],
                    $summary['invoice_templates'],
                ];
            } catch (\Throwable $e) {
                $this->error("  Error: {$e->getMessage()}");
                $rows[] = [$company->id, \Illuminate\Support\Str::limit($company->name, 30), 'ERR', '-', '-', '-', '-', '-', '-'];
            }
        }

        $this->newLine();
        $this->table(
            ['ID', 'Empresa', 'PUC', 'Imp.', 'Mon.', 'Pagos', 'Sede', 'Cons.Fin', 'Plant.'],
            $rows,
        );
        $this->info('Listo. Las cifras son "registros nuevos creados" — 0 indica que ya existían.');

        return self::SUCCESS;
    }
}
