<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Services\Products\ProductImportEngine;
use App\Services\Products\ProductImportTemplateGenerator;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Corregir un catalogo de productos que ya esta cargado.
 *
 * El caso real: se importaron todos los productos, los precios de venta
 * quedaron en cero y el inventario no entro. Hay que reimportar el archivo
 * corregido sin duplicar productos ni existencias.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ProductReimportTest extends TestCase
{
    private int $companyId;

    /** @var list<callable> */
    private array $limpiar = [];

    private array $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->firstOrFail();
        $this->companyId = (int) $user->company_id;
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }
        $this->limpiar = [];
        $this->archivos = [];
        parent::tearDown();
    }

    private function limpiarProducto(string $codigo): void
    {
        $this->limpiar[] = function () use ($codigo) {
            $ids = Product::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('code', $codigo)
                ->pluck('id');

            DB::table('inventory_opening_lines')->whereIn('product_id', $ids)->delete();
            DB::table('inventory_movements')->whereIn('product_id', $ids)->delete();
            Product::withoutGlobalScopes()->whereIn('id', $ids)->forceDelete();
        };
    }

    /**
     * XLSX con la hoja Productos y, opcionalmente, la de Inventario Inicial.
     *
     * $productos: filas asociativas por nombre de columna.
     * $stock: [product_code, location_code, qty, unit_cost]
     */
    private function archivo(array $productos, array $stock = []): string
    {
        $ruta = sys_get_temp_dir().'/zz-productos-'.uniqid().'.xlsx';
        $this->archivos[] = $ruta;

        $writer = new Writer;
        $writer->openToFile($ruta);

        $writer->getCurrentSheet()->setName('Productos');
        $columnas = ProductImportTemplateGenerator::COLUMNS;
        $writer->addRow(Row::fromValues($columnas));

        foreach ($productos as $p) {
            $writer->addRow(Row::fromValues(
                array_map(fn ($col) => $p[$col] ?? '', $columnas)
            ));
        }

        if ($stock !== []) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Inventario Inicial');
            $writer->addRow(Row::fromValues(ProductImportTemplateGenerator::STOCK_COLUMNS));
            foreach ($stock as $fila) {
                $writer->addRow(Row::fromValues($fila));
            }
        }

        $writer->close();

        return $ruta;
    }

    private function sede(): Location
    {
        return Location::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->firstOrFail();
    }

    public function test_reimportar_corrige_los_precios_sin_duplicar_productos(): void
    {
        $this->limpiarProducto('ZZ-P-1');
        $engine = app(ProductImportEngine::class);

        // Primera carga: precio de venta en cero, como quedó el catálogo real.
        $archivo = $this->archivo([[
            'code' => 'ZZ-P-1', 'name' => 'ZZ Producto', 'type' => 'good',
            'sale_price' => 0, 'purchase_price' => 2300,
        ]]);
        $parsed = $engine->parseAndValidate($archivo, $this->companyId);
        $engine->import($parsed['rows'], $this->companyId);

        $producto = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-1')->firstOrFail();
        $this->assertSame('0.00', $producto->default_sale_price);

        // Archivo corregido: mismo código, precio bueno.
        $corregido = $this->archivo([[
            'code' => 'ZZ-P-1', 'name' => 'ZZ Producto', 'type' => 'good',
            'sale_price' => 4500, 'purchase_price' => 2300,
        ]]);
        $parsed = $engine->parseAndValidate($corregido, $this->companyId);
        $resultado = $engine->import($parsed['rows'], $this->companyId);

        $this->assertSame(0, $resultado['created'], 'Ya existía: no se duplica.');
        $this->assertSame(1, $resultado['updated']);
        $this->assertSame('4500.00', $producto->fresh()->default_sale_price);

        $this->assertSame(1, Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-1')->count());
    }

    /**
     * La causa más probable de los precios en cero: la columna venía con una
     * fórmula de Excel, y getValue() devuelve el texto de la fórmula.
     */
    public function test_lee_el_precio_cuando_viene_como_formula(): void
    {
        $this->limpiarProducto('ZZ-P-2');

        $archivo = $this->xlsxConPrecioEnFormula('ZZ-P-2', 2300, 4500);

        $engine = app(ProductImportEngine::class);
        $parsed = $engine->parseAndValidate($archivo, $this->companyId);
        $engine->import($parsed['rows'], $this->companyId);

        $producto = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-2')->firstOrFail();

        $this->assertSame('4500.00', $producto->default_sale_price,
            'El resultado guardado de la fórmula, no el texto "=..." que da 0.');
    }

    public function test_no_deja_duplicar_el_inventario_al_reimportar(): void
    {
        $this->limpiarProducto('ZZ-P-3');
        $engine = app(ProductImportEngine::class);
        $sede = $this->sede();

        $cuenta = Account::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', 'like', '3%')
            ->value('id');

        if (! $cuenta) {
            $this->markTestSkipped('Sin cuenta de patrimonio en dev para la contrapartida.');
        }

        $producto = [
            'code' => 'ZZ-P-3', 'name' => 'ZZ Con Stock', 'type' => 'good',
            'sale_price' => 1000, 'purchase_price' => 500, 'track_inventory' => 'si',
        ];
        $stock = [['ZZ-P-3', $sede->code, 10, 500]];

        // Primera importación: entra el inventario.
        $archivo = $this->archivo([$producto], $stock);
        $parsed = $engine->parseAndValidate($archivo, $this->companyId);
        $resultado = $engine->import($parsed['rows'], $this->companyId, $parsed['stock_rows'], (int) $cuenta);

        $this->assertCount(1, $resultado['openings'], 'Se creó la apertura de inventario.');

        $productoId = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-3')->value('id');
        $this->limpiar[] = fn () => DB::table('inventory_openings')
            ->where('company_id', $this->companyId)
            ->where('notes', 'like', 'Apertura automática desde importación%')
            ->delete();

        $this->assertSame(10.0, app(InventoryEngine::class)->currentStock((int) $productoId, $sede->id));

        // Segunda importación del MISMO archivo: no puede duplicar.
        $archivo2 = $this->archivo([$producto], $stock);
        $parsed2 = $engine->parseAndValidate($archivo2, $this->companyId);

        try {
            $engine->import($parsed2['rows'], $this->companyId, $parsed2['stock_rows'], (int) $cuenta);
            $this->fail('Debía frenar: duplicaría el inventario.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('YA tienen existencias', $e->getMessage());
            $this->assertStringContainsString('ZZ-P-3', $e->getMessage());
        }

        $this->assertSame(10.0, app(InventoryEngine::class)->currentStock((int) $productoId, $sede->id),
            'El inventario no se movió.');
    }

    /**
     * XLSX con sale_price como formula con su resultado cacheado, igual que lo
     * guarda Excel. El escritor de OpenSpout no guarda ese cache, asi que el
     * archivo se arma a mano.
     */
    private function xlsxConPrecioEnFormula(string $codigo, float $compra, float $venta): string
    {
        $ruta = sys_get_temp_dir().'/zz-formula-prod-'.uniqid().'.xlsx';
        $this->archivos[] = $ruta;

        $columnas = ProductImportTemplateGenerator::COLUMNS;
        $idxVenta = array_search('sale_price', $columnas, true);
        $idxCompra = array_search('purchase_price', $columnas, true);

        $letra = function (int $i): string {
            $s = '';
            for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
                $s = chr(65 + $n % 26).$s;
            }

            return $s;
        };

        $celdas = function (array $valores, int $fila) use ($letra): string {
            $xml = '<row r="'.$fila.'">';
            foreach ($valores as $i => $v) {
                $ref = $letra($i).$fila;
                if (is_array($v)) {
                    $xml .= '<c r="'.$ref.'"><f>'.$v['f'].'</f><v>'.$v['v'].'</v></c>';
                } elseif ($v === '' || $v === null) {
                    continue;
                } elseif (is_numeric($v)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $v).'</t></is></c>';
                }
            }

            return $xml.'</row>';
        };

        $fila = array_fill(0, count($columnas), '');
        $fila[array_search('code', $columnas, true)] = $codigo;
        $fila[array_search('name', $columnas, true)] = 'ZZ Con Formula';
        $fila[array_search('type', $columnas, true)] = 'good';
        $fila[$idxCompra] = $compra;
        // El precio de venta calculado: lo que hace cualquiera en Excel.
        $fila[$idxVenta] = ['f' => $letra($idxCompra).'2*1.956', 'v' => $venta];

        $hoja = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$celdas($columnas, 1)
            .$celdas($fila, 2)
            .'</sheetData></worksheet>';

        $zip = new \ZipArchive;
        $zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Productos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
        $zip->close();

        return $ruta;
    }
}
