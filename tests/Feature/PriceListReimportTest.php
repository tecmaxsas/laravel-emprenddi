<?php

namespace Tests\Feature;

use App\Models\OrderTaking\PriceList;
use App\Models\OrderTaking\PriceListItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderTaking\MacDulcesImporter;
use Illuminate\Support\Facades\DB;
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
}
