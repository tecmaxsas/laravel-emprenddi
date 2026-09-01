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
     * El caso que dejo al usuario sin boton: 1.878 productos correctos y 450
     * lineas de inventario con problemas. Bloquear la correccion de todos los
     * precios por eso no le sirve a nadie.
     */
    public function test_se_pueden_importar_los_productos_aunque_falle_el_stock(): void
    {
        $this->limpiarProducto('ZZ-P-4');
        $engine = app(ProductImportEngine::class);

        $archivo = $this->archivo(
            [[
                'code' => 'ZZ-P-4', 'name' => 'ZZ Producto Bueno', 'type' => 'good',
                'sale_price' => 7700, 'purchase_price' => 5000,
            ]],
            // Sede inexistente: la linea de stock no se puede resolver.
            [['ZZ-P-4', 'SEDE-QUE-NO-EXISTE', 5, 100]],
        );

        $parsed = $engine->parseAndValidate($archivo, $this->companyId);

        $this->assertFalse($parsed['valid'], 'El archivo completo no es válido.');
        $this->assertSame(0, $parsed['summary']['errors'], 'Pero los productos sí están bien.');
        $this->assertSame(1, $parsed['summary']['stock_errors']);

        // Importar solo los productos: se ignora la hoja de stock.
        $resultado = $engine->import($parsed['rows'], $this->companyId, [], null);

        $this->assertSame(1, $resultado['created']);
        $this->assertSame([], $resultado['openings'], 'No se creó ninguna apertura.');

        $producto = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-4')->firstOrFail();
        $this->assertSame('7700.00', $producto->default_sale_price,
            'El precio se corrigió aunque el inventario no entrara.');
    }

    /**
     * La hoja se llena pegando TODOS los codigos y poniendo cantidad solo a
     * los que tienen existencias. Las filas sin cantidad no son un error: son
     * productos sin stock inicial.
     */
    public function test_omite_las_filas_sin_cantidad_en_vez_de_marcarlas_mal(): void
    {
        $this->limpiarProducto('ZZ-P-5');
        $this->limpiarProducto('ZZ-P-6');
        $engine = app(ProductImportEngine::class);
        $sede = $this->sede();

        $archivo = $this->archivo(
            [
                ['code' => 'ZZ-P-5', 'name' => 'ZZ Con Stock', 'type' => 'good', 'sale_price' => 100],
                ['code' => 'ZZ-P-6', 'name' => 'ZZ Sin Stock', 'type' => 'good', 'sale_price' => 200],
            ],
            [
                ['ZZ-P-5', $sede->code, 8, 250], // con costo real
                ['ZZ-P-6', '', '', ''],          // solo el codigo: sin stock
            ],
        );

        $parsed = $engine->parseAndValidate($archivo, $this->companyId);

        $this->assertSame(0, $parsed['summary']['stock_errors'],
            'Ni la fila sin cantidad ni el costo 0 son errores.');
        $this->assertSame(1, $parsed['summary']['stock_lines'], 'Solo queda la línea con cantidad.');
        $this->assertSame(1, $parsed['summary']['stock_skipped'], 'Y se reporta la omitida.');
        $this->assertTrue($parsed['valid'], 'El archivo se puede importar completo.');
    }

    /**
     * El costo en blanco toma el precio de compra del producto.
     *
     * El motor contable exige costo > 0: una apertura a costo cero deja el
     * inventario sin valor y hace que cada venta posterior calcule un costo
     * de ventas de 0. Antes esto se colaba en la validacion y reventaba al
     * postear, con la importacion ya "completada".
     */
    public function test_el_costo_en_blanco_toma_el_precio_de_compra(): void
    {
        $this->limpiarProducto('ZZ-P-7');
        $engine = app(ProductImportEngine::class);
        $sede = $this->sede();

        // El producto se crea en la misma pasada, con su precio de compra.
        $engine->import(
            $engine->parseAndValidate(
                $this->archivo([[
                    'code' => 'ZZ-P-7', 'name' => 'ZZ Costo Del Producto', 'type' => 'good',
                    'sale_price' => 1000, 'purchase_price' => 640,
                ]]),
                $this->companyId,
            )['rows'],
            $this->companyId,
        );

        $parsed = $engine->parseAndValidate(
            $this->archivo(
                [['code' => 'ZZ-P-7', 'name' => 'ZZ Costo Del Producto', 'type' => 'good',
                    'sale_price' => 1000, 'purchase_price' => 640]],
                [['ZZ-P-7', $sede->code, 5, '']],
            ),
            $this->companyId,
        );

        $this->assertSame(0, $parsed['summary']['stock_errors']);
        $this->assertSame(640.0, (float) $parsed['stock_rows'][0]['data']['unit_cost'],
            'Toma el precio de compra en vez de quedarse en 0.');
        $this->assertSame(1, $parsed['summary']['stock_cost_from_product']);
    }

    /**
     * Sin costo por ningun lado el inventario entra igual, sin valor.
     *
     * Es comun cargar un catalogo sin costo conocido: la cantidad es lo que
     * se necesita para operar. Se avisa la consecuencia —esas ventas saldran
     * con costo 0— pero no se bloquea.
     */
    public function test_el_stock_sin_costo_entra_sin_valor_y_se_avisa(): void
    {
        $this->limpiarProducto('ZZ-P-8');
        $engine = app(ProductImportEngine::class);
        $sede = $this->sede();

        $cuenta = Account::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('accepts_movements', true)->where('active', true)
            ->where('code', 'like', '3%')->value('id');

        if (! $cuenta) {
            $this->markTestSkipped('Sin cuenta de patrimonio en dev para la contrapartida.');
        }

        $parsed = $engine->parseAndValidate(
            $this->archivo(
                [['code' => 'ZZ-P-8', 'name' => 'ZZ Sin Costo', 'type' => 'good',
                    'sale_price' => 100, 'track_inventory' => 'si']],
                [['ZZ-P-8', $sede->code, 5, '']],
            ),
            $this->companyId,
        );

        $this->assertSame(0, $parsed['summary']['stock_errors'], 'No bloquea.');
        $this->assertSame(1, $parsed['summary']['stock_without_cost'], 'Pero sí lo cuenta para avisarlo.');
        $this->assertTrue($parsed['valid']);

        $resultado = $engine->import($parsed['rows'], $this->companyId, $parsed['stock_rows'], (int) $cuenta);

        $this->limpiar[] = fn () => DB::table('inventory_openings')
            ->where('company_id', $this->companyId)
            ->where('notes', 'like', 'Apertura automática desde importación%')
            ->delete();

        $this->assertSame([], $resultado['errors'], 'La apertura se postea.');
        $this->assertCount(1, $resultado['openings']);

        $productoId = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-8')->value('id');

        $this->assertSame(5.0, app(InventoryEngine::class)->currentStock((int) $productoId, $this->sede()->id),
            'Las existencias entran aunque no tengan valor.');

        // Todo a costo 0: no se crea un comprobante contable vacío.
        $apertura = DB::table('inventory_openings')
            ->where('company_id', $this->companyId)
            ->orderByDesc('id')->first();
        $this->assertNull($apertura->journal_entry_id,
            'Un asiento que mueve 0 en ambos lados no aporta nada a los libros.');
    }

    /**
     * Cargar existencias en una sede implica que el producto esta en esa
     * sede. Antes la importacion cargaba el stock pero dejaba los productos
     * con "Sedes 0", y de esa asignacion salen el stock minimo, el punto de
     * reorden y el precio propio de la sede.
     */
    public function test_cargar_stock_asigna_el_producto_a_la_sede(): void
    {
        $this->limpiarProducto('ZZ-P-9');
        $engine = app(ProductImportEngine::class);
        $sede = $this->sede();

        $cuenta = Account::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('accepts_movements', true)->where('active', true)
            ->where('code', 'like', '3%')->value('id');

        if (! $cuenta) {
            $this->markTestSkipped('Sin cuenta de patrimonio en dev para la contrapartida.');
        }

        $archivo = $this->archivo(
            [['code' => 'ZZ-P-9', 'name' => 'ZZ En Sede', 'type' => 'good',
                'sale_price' => 900, 'purchase_price' => 400, 'track_inventory' => 'si']],
            [['ZZ-P-9', $sede->code, 6, 400]],
        );

        $parsed = $engine->parseAndValidate($archivo, $this->companyId);
        $resultado = $engine->import($parsed['rows'], $this->companyId, $parsed['stock_rows'], (int) $cuenta);

        $this->assertCount(1, $resultado['openings']);

        $this->limpiar[] = fn () => DB::table('inventory_openings')
            ->where('company_id', $this->companyId)
            ->where('notes', 'like', 'Apertura automática desde importación%')
            ->delete();

        $producto = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('code', 'ZZ-P-9')->firstOrFail();

        $this->assertSame(1, $producto->locations()->count(),
            'El producto queda asignado a la sede donde tiene existencias.');
        $this->assertSame($sede->id, $producto->locations()->first()->id);
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
