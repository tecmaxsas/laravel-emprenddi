<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\OrderTaking\MacDulcesImporter;
use Illuminate\Console\Command;

/**
 * Importa los 3 XLSX de MAC DULCES (o cualquier operacion con la misma
 * estructura de plantillas). Delega en App\Services\OrderTaking\MacDulcesImporter
 * — el mismo servicio que usa la pagina Filament de importacion.
 */
class OrderTakingImportMacDulces extends Command
{
    protected $signature = 'order-taking:import-mac-dulces
        {--company= : ID de la empresa target}
        {--dir= : Directorio con los 2 XLSX (nombres estandar MAC DULCES)}
        {--precios= : Ruta absoluta al XLSX de listas de precios (alternativa a --dir)}
        {--clientes= : Ruta absoluta al XLSX de clientes (alternativa a --dir)}';

    protected $description = 'Importa productos, listas de precios y clientes de MAC DULCES';

    public function handle(MacDulcesImporter $importer): int
    {
        $companyId = (int) $this->option('company');
        if ($companyId <= 0) {
            $this->error('Falta --company=ID. Usa: php artisan order-taking:import-mac-dulces --company=11');
            return self::FAILURE;
        }
        $company = Company::find($companyId);
        if (! $company) {
            $this->error("Empresa {$companyId} no encontrada.");
            return self::FAILURE;
        }

        // Resolver paths: si vinieron --precios/--clientes se usan directo;
        // sino se arma desde --dir con los nombres estandar MAC DULCES.
        $preciosPath = $this->option('precios');
        $clientesPath = $this->option('clientes');
        if (! $preciosPath || ! $clientesPath) {
            $dir = $this->option('dir') ?: 'C:/Users/Usuario/Downloads';
            $preciosPath = $preciosPath ?: $dir.DIRECTORY_SEPARATOR.'3. LISTAS DE PRECIOS ENE 2026.xlsx';
            $clientesPath = $clientesPath ?: $dir.DIRECTORY_SEPARATOR.'2. CATALOGO DE CLIENTES MAC DULCES.xlsx';
        }

        $this->info("Empresa: {$company->name} (ID {$companyId})");
        $this->info("Precios: {$preciosPath}");
        $this->info("Clientes: {$clientesPath}");
        $this->newLine();

        try {
            $result = $importer->import($companyId, $preciosPath, $clientesPath);
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['Metrica', 'Cantidad'],
            [
                ['Productos creados', $result['products_created']],
                ['Productos actualizados', $result['products_updated']],
                ['Listas de precios', $result['price_lists']],
                ['Items de precio', $result['price_items']],
                ['Clientes creados', $result['customers_created']],
                ['Clientes actualizados', $result['customers_updated']],
            ],
        );
        $this->info('✓ Importacion completa.');
        return self::SUCCESS;
    }
}
