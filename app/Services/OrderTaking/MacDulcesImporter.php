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
    /**
     * El archivo de clientes es opcional: para corregir precios de un catalogo
     * ya cargado no hace falta, y reimportarlo pisaria datos que se hayan
     * ajustado a mano despues (lista asignada, condiciones de pago, horarios).
     */
    public function import(int $companyId, string $preciosPath, ?string $clientesPath = null): array
    {
        if (! file_exists($preciosPath)) {
            throw new RuntimeException("No encontre el archivo de precios: {$preciosPath}");
        }
        if ($clientesPath !== null && ! file_exists($clientesPath)) {
            throw new RuntimeException("No encontre el archivo de clientes: {$clientesPath}");
        }

        $preciosRows = $this->readSheet($preciosPath);
        array_shift($preciosRows);

        $clientesRows = null;
        if ($clientesPath !== null) {
            $clientesRows = $this->readSheet($clientesPath);
            array_shift($clientesRows);
        }

        return DB::transaction(function () use ($companyId, $preciosRows, $clientesRows) {
            $precios = $this->importProductosYPrecios($companyId, $preciosRows);

            $clientes = $clientesRows === null
                ? ['customers_created' => 0, 'customers_updated' => 0, 'customers_skipped' => true]
                : $this->importClientes($companyId, $clientesRows);

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

        // Primero se valida TODO el archivo y despues se escribe. Un archivo
        // sin desglose de IVA ya se importo una vez en silencio y dejo las
        // listas con base y IVA en cero: el pedido decia "IVA $0" con un total
        // que si tenia IVA, y de ahi salieron cuentas mal en cascada.
        $incoherentes = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r[2] ?? ''));
            $listNum = (int) ($r[3] ?? 0);

            if ($code === '' || $listNum < 1 || $listNum > 4) {
                continue;
            }

            $total = (float) ($r[4] ?? 0);
            $base = (float) ($r[5] ?? 0);
            $tax = (float) ($r[6] ?? 0);

            // Tolerancia de un peso: los redondeos del Excel no son un error.
            // Un producto exento cuadra igual, con base = total e IVA = 0.
            if ($total > 0 && abs(($base + $tax) - $total) > 1.0) {
                $incoherentes[] = sprintf(
                    '%s (lista %d): base %s + IVA %s = %s, pero el total dice %s',
                    $code,
                    $listNum,
                    number_format($base, 2, ',', '.'),
                    number_format($tax, 2, ',', '.'),
                    number_format($base + $tax, 2, ',', '.'),
                    number_format($total, 2, ',', '.'),
                );
            }
        }

        if ($incoherentes !== []) {
            throw new RuntimeException(sprintf(
                "%d precios no cuadran: la base más el IVA no dan el total. No se importó nada.\n\n%s%s",
                count($incoherentes),
                implode("\n", array_slice($incoherentes, 0, 8)),
                count($incoherentes) > 8 ? "\n… y ".(count($incoherentes) - 8).' más.' : '',
            ));
        }

        $priceItems = 0;
        $priceItemsChanged = 0;
        foreach ($rows as $r) {
            $code = trim((string) ($r[2] ?? ''));
            $listNum = (int) ($r[3] ?? 0);
            $total = (float) ($r[4] ?? 0);
            $base = (float) ($r[5] ?? 0);
            $tax = (float) ($r[6] ?? 0);

            if ($code === '' || $listNum < 1 || $listNum > 4) continue;
            if (! isset($productMap[$code], $priceLists[$listNum])) continue;

            $nuevos = [
                'company_id' => $companyId,
                'price_before_tax' => round($base, 4),
                'tax_amount' => round($tax, 4),
                'price_at_public' => round($total, 2),
            ];

            $anterior = PriceListItem::query()
                ->where('price_list_id', $priceLists[$listNum]->id)
                ->where('product_id', $productMap[$code])
                ->first();

            PriceListItem::updateOrCreate(
                [
                    'price_list_id' => $priceLists[$listNum]->id,
                    'product_id' => $productMap[$code],
                ],
                $nuevos,
            );

            // Cuantos precios cambiaron de verdad: es lo que dice si la
            // correccion surtio efecto o si el archivo traia lo mismo.
            if (! $anterior
                || abs((float) $anterior->price_before_tax - $nuevos['price_before_tax']) > 0.0001
                || abs((float) $anterior->tax_amount - $nuevos['tax_amount']) > 0.0001
                || abs((float) $anterior->price_at_public - $nuevos['price_at_public']) > 0.0001) {
                $priceItemsChanged++;
            }

            $priceItems++;
        }

        return [
            'products_created' => $productsCreated,
            'products_updated' => $productsUpdated,
            'price_lists' => count($priceLists),
            'price_items' => $priceItems,
            'price_items_changed' => $priceItemsChanged,
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
