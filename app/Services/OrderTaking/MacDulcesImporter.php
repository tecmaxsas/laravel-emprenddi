<?php

namespace App\Services\OrderTaking;

use App\Models\Category;
use App\Models\OrderTaking\PriceList;
use App\Models\OrderTaking\PriceListItem;
use App\Models\Product;
use App\Models\ThirdParty;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Importador de los 3 XLSX de MAC DULCES (o cualquier operacion con la
 * misma estructura de plantillas). Reusable desde el comando artisan y
 * desde la pagina Filament de importacion.
 *
 * NOTA: la plantilla de pedidos (archivo 1) no se importa — es un ejemplo
 * visual, no un dato maestro. Se ignora si viene.
 */
class MacDulcesImporter
{
    /**
     * @return array{
     *     products_created: int,
     *     products_updated: int,
     *     price_lists: int,
     *     price_items: int,
     *     customers_created: int,
     *     customers_updated: int,
     * }
     */
    public function import(int $companyId, string $preciosPath, string $clientesPath): array
    {
        if (! file_exists($preciosPath)) {
            throw new RuntimeException("No encontre el archivo de precios: {$preciosPath}");
        }
        if (! file_exists($clientesPath)) {
            throw new RuntimeException("No encontre el archivo de clientes: {$clientesPath}");
        }

        $preciosRows = $this->readSheet($preciosPath);
        $clientesRows = $this->readSheet($clientesPath);

        array_shift($preciosRows);
        array_shift($clientesRows);

        return DB::transaction(function () use ($companyId, $preciosRows, $clientesRows) {
            $precios = $this->importProductosYPrecios($companyId, $preciosRows);
            $clientes = $this->importClientes($companyId, $clientesRows);
            return array_merge($precios, $clientes);
        });
    }

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
     * @return array{products_created: int, products_updated: int, price_lists: int, price_items: int}
     */
    protected function importProductosYPrecios(int $companyId, array $rows): array
    {
        $category = Category::firstOrCreate(
            ['company_id' => $companyId, 'name' => 'Dulces'],
            ['active' => true],
        );

        $priceLists = [];
        for ($n = 1; $n <= 4; $n++) {
            $pl = PriceList::firstOrCreate(
                ['company_id' => $companyId, 'code' => "L{$n}"],
                ['name' => "Lista {$n}", 'active' => true],
            );
            $priceLists[$n] = $pl;
        }

        $productData = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r[2] ?? ''));
            $desc = trim((string) ($r[1] ?? ''));
            if ($code === '' || $desc === '') continue;
            if (! isset($productData[$code])) {
                $productData[$code] = $desc;
            }
        }

        $productsCreated = 0; $productsUpdated = 0; $productMap = [];
        foreach ($productData as $code => $desc) {
            $existing = Product::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->first();
            if ($existing) {
                $productsUpdated++;
                $productMap[$code] = $existing->id;
            } else {
                $p = Product::create([
                    'company_id' => $companyId,
                    'code' => $code,
                    'name' => $desc,
                    'category_id' => $category->id,
                    'type' => 'good',
                    'unit_of_measure' => 'unit',
                    'default_sale_price' => 0,
                    'default_purchase_price' => 0,
                    'is_sellable' => true,
                    'is_purchasable' => true,
                    'active' => true,
                ]);
                $productsCreated++;
                $productMap[$code] = $p->id;
            }
        }

        $priceItems = 0;
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
            $priceItems++;
        }

        return [
            'products_created' => $productsCreated,
            'products_updated' => $productsUpdated,
            'price_lists' => count($priceLists),
            'price_items' => $priceItems,
        ];
    }

    /**
     * @return array{customers_created: int, customers_updated: int}
     */
    protected function importClientes(int $companyId, array $rows): array
    {
        $listByCode = PriceList::query()
            ->where('company_id', $companyId)
            ->get()->keyBy('code');

        $created = 0; $updated = 0;
        foreach ($rows as $r) {
            $name = trim((string) ($r[0] ?? ''));
            $nit = trim((string) ($r[1] ?? ''));
            if ($name === '' || $nit === '') continue;

            $codListNew = (int) ($r[7] ?? 0);
            $priceListId = null;
            if ($codListNew >= 1 && $codListNew <= 4) {
                $priceListId = $listByCode->get("L{$codListNew}")?->id;
            }

            $retention = (float) ($r[14] ?? 0);
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

        return [
            'customers_created' => $created,
            'customers_updated' => $updated,
        ];
    }
}
