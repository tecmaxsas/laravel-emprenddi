<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Company;
use App\Models\OrderTaking\PriceList;
use App\Models\OrderTaking\PriceListItem;
use App\Models\Product;
use App\Models\ThirdParty;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Importa los 3 XLSX de MAC DULCES (o cualquier operacion con la misma
 * estructura de plantillas):
 *   - 2. CATALOGO DE CLIENTES MAC DULCES.xlsx
 *   - 3. LISTAS DE PRECIOS ENE 2026.xlsx
 *   - `1. PLANTILLA PEDIDOS 2026.xlsx  (solo se usa para deduccionde productos si hace falta)
 *
 * Crea productos, listas de precios y clientes en la empresa target,
 * asociando a cada cliente su lista de precios por default.
 */
class OrderTakingImportMacDulces extends Command
{
    protected $signature = 'order-taking:import-mac-dulces
        {--company= : ID de la empresa target}
        {--dir= : Directorio con los 3 XLSX (default: Descargas del usuario)}
        {--dry-run : Solo mostrar lo que se crearia sin escribir}';

    protected $description = 'Importa productos, listas de precios y clientes de MAC DULCES';

    public function handle(): int
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

        $dir = $this->option('dir') ?: 'C:/Users/Usuario/Downloads';
        $dryRun = (bool) $this->option('dry-run');

        // Archivos esperados (con backtick al inicio del de pedidos, tal cual el user los tiene)
        $clientesPath = $dir.DIRECTORY_SEPARATOR.'2. CATALOGO DE CLIENTES MAC DULCES.xlsx';
        $preciosPath = $dir.DIRECTORY_SEPARATOR.'3. LISTAS DE PRECIOS ENE 2026.xlsx';

        foreach (['clientes' => $clientesPath, 'precios' => $preciosPath] as $key => $path) {
            if (! file_exists($path)) {
                $this->error("No encontre {$key}: {$path}");
                return self::FAILURE;
            }
        }

        $this->info("Empresa: {$company->name} (ID {$companyId})");
        $this->info("Directorio: {$dir}");
        $this->info($dryRun ? '*** DRY RUN — no se escribe nada ***' : 'Modo real: se escribira en BD.');
        $this->newLine();

        // Parsear XLSX -> arrays
        $preciosRows = $this->readSheet($preciosPath, 0); // primera hoja
        $clientesRows = $this->readSheet($clientesPath, 0);

        // Descartar header (fila 1)
        array_shift($preciosRows);
        array_shift($clientesRows);

        $this->info('Precios leidos: '.count($preciosRows).' filas');
        $this->info('Clientes leidos: '.count($clientesRows).' filas');

        if ($dryRun) {
            $this->line('Muestra de primeras 3 filas precios:');
            foreach (array_slice($preciosRows, 0, 3) as $r) {
                $this->line('  '.json_encode($r, JSON_UNESCAPED_UNICODE));
            }
            $this->line('Muestra de primeras 3 filas clientes:');
            foreach (array_slice($clientesRows, 0, 3) as $r) {
                $this->line('  '.json_encode($r, JSON_UNESCAPED_UNICODE));
            }
            return self::SUCCESS;
        }

        DB::transaction(function () use ($companyId, $preciosRows, $clientesRows) {
            $this->importProductosYPrecios($companyId, $preciosRows);
            $this->importClientes($companyId, $clientesRows);
        });

        $this->newLine();
        $this->info('✓ Importacion completa.');
        return self::SUCCESS;
    }

    /**
     * Lee una hoja del XLSX y devuelve array de arrays (cada fila es
     * array indexado por posicion de columna 0..N).
     */
    protected function readSheet(string $path, int $sheetIndex = 0): array
    {
        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        $idx = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($idx !== $sheetIndex) { $idx++; continue; }
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                foreach ($row->getCells() as $c) {
                    $cells[] = $c->getValue();
                }
                $rows[] = $cells;
            }
            break;
        }
        $reader->close();
        return $rows;
    }

    /**
     * Cada fila de precios: [COD+LISTA, DESCRIPCION, COD, LISTA, TOTAL, ANTES_IMPUESTO, IMPUESTO, VERIFICAR]
     */
    protected function importProductosYPrecios(int $companyId, array $rows): void
    {
        $this->info('→ Creando categoria y productos...');
        $category = Category::firstOrCreate(
            ['company_id' => $companyId, 'name' => 'Dulces'],
            ['active' => true],
        );

        // 4 listas fijas (Lista 1..4)
        $priceLists = [];
        for ($n = 1; $n <= 4; $n++) {
            $pl = PriceList::firstOrCreate(
                ['company_id' => $companyId, 'code' => "L{$n}"],
                ['name' => "Lista {$n}", 'active' => true],
            );
            $priceLists[$n] = $pl;
        }
        $this->info('  ✓ 4 listas de precios creadas/actualizadas');

        // Agrupar filas por producto (COD) tomando la primera descripcion vista
        $productData = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r[2] ?? ''));
            $desc = trim((string) ($r[1] ?? ''));
            if ($code === '' || $desc === '') continue;
            if (! isset($productData[$code])) {
                $productData[$code] = $desc;
            }
        }
        $this->info('  ✓ '.count($productData).' productos unicos detectados');

        $productMap = [];
        foreach ($productData as $code => $desc) {
            $p = Product::firstOrCreate(
                ['company_id' => $companyId, 'code' => $code],
                [
                    'name' => $desc,
                    'category_id' => $category->id,
                    'type' => 'simple',
                    'unit' => 'unidad',
                    'sale_price' => 0,
                    'active' => true,
                ],
            );
            $productMap[$code] = $p->id;
        }

        // Crear items de precio (una fila por par producto-lista)
        $this->info('→ Creando items de precio...');
        $created = 0;
        foreach ($rows as $r) {
            $code = trim((string) ($r[2] ?? ''));
            $listNum = (int) ($r[3] ?? 0);
            $total = (float) ($r[4] ?? 0);
            $base = (float) ($r[5] ?? 0);
            $tax = (float) ($r[6] ?? 0);

            if ($code === '' || $listNum < 1 || $listNum > 4) continue;
            if (! isset($productMap[$code], $priceLists[$listNum])) continue;

            PriceListItem::updateOrCreate(
                [
                    'price_list_id' => $priceLists[$listNum]->id,
                    'product_id' => $productMap[$code],
                ],
                [
                    'company_id' => $companyId,
                    'price_before_tax' => round($base, 4),
                    'tax_amount' => round($tax, 4),
                    'price_at_public' => round($total, 2),
                ],
            );
            $created++;
        }
        $this->info("  ✓ {$created} precios creados/actualizados");
    }

    /**
     * Cada fila de clientes:
     * [CLIENTE, NIT, correo_ref, negocio, CONTACTO, fijo, celular, COD_LISTA_NEW, LISTA, CIUDAD, DIR_ENTREGA, DIR_FACTURA, FORMA_PAGO, HORARIO, %RETENCION]
     */
    protected function importClientes(int $companyId, array $rows): void
    {
        $this->info('→ Creando clientes...');

        $listByCodNew = PriceList::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('code');

        $created = 0; $updated = 0;
        foreach ($rows as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $nit = trim((string) ($r[1] ?? ''));
            if ($name === '' || $nit === '') continue;

            $codListNew = (int) ($r[7] ?? 0);
            $priceListId = null;
            if ($codListNew >= 1 && $codListNew <= 4) {
                $priceListId = $listByCodNew->get("L{$codListNew}")?->id;
            }

            $retention = (float) ($r[14] ?? 0);
            // El XLSX trae 0.025 = 2.5%. Guardamos como decimal (0.025).
            if ($retention > 1) $retention = $retention / 100;

            $tp = ThirdParty::updateOrCreate(
                ['company_id' => $companyId, 'document_number' => $nit],
                [
                    'person_type' => strlen($nit) >= 9 ? 'juridica' : 'natural',
                    'document_type' => strlen($nit) >= 9 ? 'nit' : 'cc',
                    'name' => $name,
                    'contact_person' => trim((string) ($r[4] ?? '')) ?: null,
                    'default_price_list_id' => $priceListId,
                    'city' => trim((string) ($r[9] ?? '')) ?: null,
                    'address' => trim((string) ($r[10] ?? '')) ?: null,
                    'payment_terms' => trim((string) ($r[12] ?? '')) ?: null,
                    'delivery_horario' => trim((string) ($r[13] ?? '')) ?: null,
                    'retention_percent' => round($retention, 4),
                    'phone' => trim((string) ($r[5] ?? '')) ?: null,
                    'mobile' => trim((string) ($r[6] ?? '')) ?: null,
                    'is_customer' => true,
                    'active' => true,
                ],
            );

            $tp->wasRecentlyCreated ? $created++ : $updated++;
        }
        $this->info("  ✓ Clientes: {$created} creados, {$updated} actualizados");
    }
}
