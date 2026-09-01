<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OrderTaking\PriceList;
use App\Models\OrderTaking\PriceListItem;
use App\Models\Product;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\OrderTaking\MacDulcesImporter;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Corregir los precios de un catalogo que ya esta cargado.
 *
 * El caso real: el catalogo se importo con los precios SIN IVA y hay que
 * reimportarlo con el archivo corregido, sin duplicar los productos que ya
 * existen y sin tocar los clientes.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PriceListReimportTest extends TestCase
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

    /**
     * Genera el XLSX de listas de precios con el layout que espera el
     * importador: [_, descripcion, codigo, lista, total, base, iva].
     *
     * @param  list<array{0:string,1:string,2:int,3:float,4:float,5:float}>  $filas
     */
    private function archivoDePrecios(array $filas): string
    {
        $ruta = sys_get_temp_dir().'/zz-precios-'.uniqid().'.xlsx';

        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues(['', 'DESCRIPCION', 'REFERENCIA', 'LISTA', 'TOTAL', 'BASE', 'IVA']));

        foreach ($filas as [$codigo, $descripcion, $lista, $total, $base, $iva]) {
            $writer->addRow(Row::fromValues(['', $descripcion, $codigo, $lista, $total, $base, $iva]));
        }

        $writer->close();
        $this->archivos[] = $ruta;

        return $ruta;
    }

    private function limpiarProducto(string $codigo): void
    {
        $this->limpiar[] = function () use ($codigo) {
            $ids = Product::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('code', $codigo)
                ->pluck('id');

            PriceListItem::withoutGlobalScopes()->whereIn('product_id', $ids)->forceDelete();
            DB::table('inventory_movements')->whereIn('product_id', $ids)->delete();
            Product::withoutGlobalScopes()->whereIn('id', $ids)->forceDelete();
        };
    }

    private function precioDe(string $codigo, int $listaNum): PriceListItem
    {
        $producto = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('code', $codigo)
            ->firstOrFail();

        $lista = PriceList::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('code', "L{$listaNum}")
            ->firstOrFail();

        return PriceListItem::withoutGlobalScopes()
            ->where('product_id', $producto->id)
            ->where('price_list_id', $lista->id)
            ->firstOrFail();
    }

    public function test_reimportar_corrige_los_precios_sin_duplicar_productos(): void
    {
        $this->limpiarProducto('ZZ-IMP-1');
        $importer = app(MacDulcesImporter::class);

        // Primera carga, ya correcta.
        $primera = $importer->import($this->companyId, $this->archivoDePrecios([
            ['ZZ-IMP-1', 'ZZ BOLA ACIDA', 1, 152520.0, 128160.0, 24360.0],
        ]));

        $this->assertSame(1, $primera['products_created']);
        $this->assertTrue($primera['customers_skipped'], 'Sin archivo de clientes no se toca a nadie.');

        // El cliente manda precios corregidos: mismo codigo, otro valor.
        $segunda = $importer->import($this->companyId, $this->archivoDePrecios([
            ['ZZ-IMP-1', 'ZZ BOLA ACIDA', 1, 160000.0, 134453.78, 25546.22],
        ]));

        $this->assertSame(0, $segunda['products_created'], 'El producto ya existía: no se duplica.');
        $this->assertSame(1, $segunda['products_updated']);
        $this->assertSame(1, $segunda['price_items_changed'], 'El precio sí cambió.');

        $this->assertSame(1, Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('code', 'ZZ-IMP-1')
            ->count(), 'Un solo producto con ese código.');

        $precio = $this->precioDe('ZZ-IMP-1', 1);
        $this->assertSame('134453.7800', $precio->price_before_tax);
        $this->assertSame('25546.2200', $precio->tax_amount);
        $this->assertSame('160000.00', $precio->price_at_public);
    }

    public function test_reimportar_lo_mismo_no_reporta_cambios(): void
    {
        $this->limpiarProducto('ZZ-IMP-2');
        $importer = app(MacDulcesImporter::class);

        $filas = [['ZZ-IMP-2', 'ZZ CHICLE', 1, 152520.0, 128160.0, 24360.0]];

        $importer->import($this->companyId, $this->archivoDePrecios($filas));
        $segunda = $importer->import($this->companyId, $this->archivoDePrecios($filas));

        $this->assertSame(1, $segunda['price_items'], 'Se procesa igual.');
        $this->assertSame(0, $segunda['price_items_changed'], 'Pero nada cambió.');
    }

    /**
     * El error original: un archivo cuyas columnas de base e IVA venian
     * vacias se importo en silencio y dejo las listas en cero.
     */
    public function test_un_archivo_sin_desglose_de_iva_se_rechaza_entero(): void
    {
        $this->limpiarProducto('ZZ-IMP-3');
        $importer = app(MacDulcesImporter::class);

        $archivo = $this->archivoDePrecios([
            ['ZZ-IMP-3', 'ZZ SIN DESGLOSE', 1, 152520.0, 0.0, 0.0],
        ]);

        try {
            $importer->import($this->companyId, $archivo);
            $this->fail('Debía rechazar el archivo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no cuadran', $e->getMessage());
            $this->assertStringContainsString('ZZ-IMP-3', $e->getMessage(),
                'El mensaje tiene que decir qué producto está mal.');
        }

        $this->assertSame(0, Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('code', 'ZZ-IMP-3')
            ->count(), 'No se importó nada: la transacción se revierte entera.');
    }

    /** Un producto exento cuadra: base igual al total e IVA en cero. */
    public function test_un_producto_exento_pasa_la_validacion(): void
    {
        $this->limpiarProducto('ZZ-IMP-4');

        $resultado = app(MacDulcesImporter::class)->import($this->companyId, $this->archivoDePrecios([
            ['ZZ-IMP-4', 'ZZ EXENTO', 1, 50000.0, 50000.0, 0.0],
        ]));

        $this->assertSame(1, $resultado['price_items']);
        $this->assertSame('0.0000', $this->precioDe('ZZ-IMP-4', 1)->tax_amount);
    }

    /**
     * Las plantillas tienen que servir para lo que existe: se descargan, se
     * llenan y el importador las lee. Si el generador y el importador se
     * separan, esto lo detecta antes que el cliente — ya paso una vez, con las
     * instrucciones en la primera hoja tapando los datos.
     */
    public function test_la_plantilla_de_precios_la_puede_leer_el_importador(): void
    {
        $this->conModuloActivo();

        $respuesta = $this->get(route('order-taking.import.template', 'precios'));

        if ($respuesta->getStatusCode() === 403) {
            $this->markTestSkipped('El usuario de dev no tiene el permiso order_taking.manage.');
        }

        $respuesta->assertSuccessful();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $respuesta->headers->get('Content-Type'),
        );

        $archivo = $this->guardar($respuesta->streamedContent());

        // Los ejemplos que trae la plantilla se importan tal cual.
        $this->limpiarProducto('MG-67');
        $this->limpiarProducto('EX-01');

        $resultado = app(MacDulcesImporter::class)->import($this->companyId, $archivo);

        $this->assertSame(3, $resultado['price_items'],
            'Las 3 filas de ejemplo: MG-67 en dos listas y un exento.');
        $this->assertTrue($resultado['customers_skipped']);

        $precio = $this->precioDe('MG-67', 1);
        $this->assertSame(
            round((float) $precio->price_before_tax + (float) $precio->tax_amount, 2),
            round((float) $precio->price_at_public, 2),
            'Los ejemplos de la plantilla tienen que cuadrar.'
        );
    }

    public function test_la_plantilla_de_clientes_la_puede_leer_el_importador(): void
    {
        $this->conModuloActivo();

        $respuesta = $this->get(route('order-taking.import.template', 'clientes'));

        if ($respuesta->getStatusCode() === 403) {
            $this->markTestSkipped('El usuario de dev no tiene el permiso order_taking.manage.');
        }

        $respuesta->assertSuccessful();

        $clientes = $this->guardar($respuesta->streamedContent());
        // El de precios solo para poder llamar al importador: lo que se prueba
        // es que la hoja de clientes se lea desde su propio archivo.
        $precios = $this->archivoDePrecios([['ZZ-IMP-5', 'ZZ X', 1, 1000.0, 1000.0, 0.0]]);
        $this->limpiarProducto('ZZ-IMP-5');

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('document_number', '901234567')
            ->forceDelete();

        $resultado = app(MacDulcesImporter::class)->import($this->companyId, $precios, $clientes);

        $this->assertSame(1, $resultado['customers_created'] + $resultado['customers_updated'],
            'La fila de ejemplo de la plantilla de clientes se lee.');
    }

    /**
     * El caso real que reventó: el cliente calculó la base y el IVA con
     * fórmulas de Excel. getValue() de una celda con fórmula devuelve el TEXTO
     * ("=E2-F2"), que al convertirlo a número da 0 — así que el archivo se leía
     * como si esas columnas estuvieran vacías, aunque en pantalla se vieran
     * los valores correctos.
     *
     * El XLSX se arma a mano porque el escritor de OpenSpout no guarda el
     * resultado cacheado de una fórmula; Excel sí lo hace, y es justo ese
     * valor el que hay que leer.
     */
    public function test_lee_la_base_y_el_iva_cuando_vienen_como_formulas(): void
    {
        $this->limpiarProducto('ZZ-FORM-1');

        $archivo = $this->xlsxConFormulas();
        $resultado = app(MacDulcesImporter::class)->import($this->companyId, $archivo);

        $this->assertSame(1, $resultado['price_items'], 'La fila con fórmulas se importa.');

        $precio = $this->precioDe('ZZ-FORM-1', 1);
        $this->assertSame('125042.0000', $precio->price_before_tax);
        $this->assertSame('23758.0000', $precio->tax_amount);
        $this->assertSame('148800.00', $precio->price_at_public);
    }

    /**
     * XLSX minimo con una fila de datos donde BASE e IVA son formulas con su
     * resultado cacheado en <v>, exactamente como lo guarda Excel.
     */
    private function xlsxConFormulas(): string
    {
        $ruta = sys_get_temp_dir().'/zz-formulas-'.uniqid().'.xlsx';
        $this->archivos[] = $ruta;

        $fila = function (int $n, array $celdas): string {
            $xml = '<row r="'.$n.'">';
            foreach ($celdas as $col => $celda) {
                $ref = $col.$n;
                $xml .= is_array($celda)
                    ? '<c r="'.$ref.'"><f>'.$celda['f'].'</f><v>'.$celda['v'].'</v></c>'
                    : (is_numeric($celda)
                        ? '<c r="'.$ref.'"><v>'.$celda.'</v></c>'
                        : '<c r="'.$ref.'" t="inlineStr"><is><t>'.$celda.'</t></is></c>');
            }

            return $xml.'</row>';
        };

        $hoja = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$fila(1, ['A' => ' ', 'B' => 'DESCRIPCION', 'C' => 'REFERENCIA', 'D' => 'LISTA', 'E' => 'TOTAL', 'F' => 'BASE', 'G' => 'IVA'])
            .$fila(2, [
                'A' => ' ',
                'B' => 'ZZ BOLA CON FORMULA',
                'C' => 'ZZ-FORM-1',
                'D' => '1',
                'E' => '148800',
                'F' => ['f' => 'ROUND(E2/1.19,0)', 'v' => '125042'],
                'G' => ['f' => 'E2-F2', 'v' => '23758'],
            ])
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
            .'<sheets><sheet name="Listas de precios" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
        $zip->close();

        return $ruta;
    }

    /**
     * Si la formula viene sin resultado guardado no hay nada que leer, pero el
     * mensaje tiene que decir que hacer en vez de solo "no cuadra".
     */
    public function test_una_formula_sin_resultado_guardado_se_explica(): void
    {
        $this->limpiarProducto('ZZ-FORM-2');

        $ruta = sys_get_temp_dir().'/zz-sin-cache-'.uniqid().'.xlsx';
        $this->archivos[] = $ruta;

        // El escritor de OpenSpout escribe <f> sin <v>: justo el caso.
        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues(['', 'DESCRIPCION', 'REFERENCIA', 'LISTA', 'TOTAL', 'BASE', 'IVA']));
        $writer->addRow(new Row([
            Cell::fromValue(''),
            Cell::fromValue('ZZ SIN CACHE'),
            Cell::fromValue('ZZ-FORM-2'),
            Cell::fromValue(1),
            Cell::fromValue(148800),
            new FormulaCell('=ROUND(E2/1.19,0)', null),
            new FormulaCell('=E2-F2', null),
        ]));
        $writer->close();

        try {
            app(MacDulcesImporter::class)->import($this->companyId, $ruta);
            $this->fail('Debía rechazarlo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no quedó guardado', $e->getMessage());
            $this->assertStringContainsString('Solo valores', $e->getMessage(),
                'El mensaje tiene que decir cómo arreglarlo.');
        }
    }

    private function conModuloActivo(): void
    {
        $company = Company::find($this->companyId);
        $modulos = $company->active_modules ?? [];
        $company->update(['active_modules' => array_values(array_unique([...$modulos, 'order_taking']))]);
        app(CurrentCompany::class)->set($company->refresh());
        $this->limpiar[] = fn () => $company->update(['active_modules' => $modulos]);
    }

    private function guardar(string $contenido): string
    {
        $ruta = sys_get_temp_dir().'/zz-plantilla-'.uniqid().'.xlsx';
        file_put_contents($ruta, $contenido);
        $this->archivos[] = $ruta;

        return $ruta;
    }
}
