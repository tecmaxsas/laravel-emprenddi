<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\ProductOptions;
use App\Support\TaxOptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los selectores de producto, impuesto y retención de las facturas.
 *
 * Todos exigían escribir para mostrar algo: se abrían vacíos, con un «escribe
 * para buscar». Para el producto tiene sentido —pueden ser miles— pero obligar
 * a teclear para que aparezca el IVA del 19 %, cuando la empresa tiene ocho
 * impuestos en total, solo hacía lenta la digitación.
 *
 * Ahora impuestos y retenciones se cargan completos, y los productos vienen con
 * una primera tanda que basta para empezar sin escribir.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class InvoiceSelectOptionsTest extends TestCase
{
    private const RECURSOS = [
        'SaleInvoiceResource',
        'PurchaseInvoiceResource',
        'QuotationResource',
        'CreditDebitNoteResource',
    ];

    private Company $company;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        TaxOptions::forget();
        ProductOptions::forget();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        TaxOptions::forget();
        ProductOptions::forget();

        parent::tearDown();
    }

    /** Ningún selector de línea o de retención puede abrirse vacío. */
    public function test_ningun_selector_de_factura_se_abre_vacio(): void
    {
        $problemas = [];

        foreach (self::RECURSOS as $recurso) {
            $codigo = file_get_contents(app_path("Filament/App/Resources/{$recurso}.php"));

            // Un selector con búsqueda pero sin `options()` no muestra nada
            // hasta que el usuario adivina qué escribir.
            preg_match_all("/Select::make\('(product_id|tax_id)'\)(.*?)->columnSpan/s", $codigo, $selects, PREG_SET_ORDER);

            $this->assertNotEmpty($selects, "{$recurso}: no se encontraron selectores de línea.");

            foreach ($selects as $select) {
                if (! str_contains($select[2], '->options(')) {
                    $problemas[] = "{$recurso}: el selector de {$select[1]} no precarga opciones";
                }
            }
        }

        $this->assertSame([], $problemas, implode("\n", $problemas));
    }

    /**
     * Una retención no es un impuesto de línea: tiene su propia sección, su
     * propia base y su propia cuenta. Ofrecerla junto al IVA invita a aplicarla
     * como si sumara al total.
     */
    public function test_las_retenciones_no_aparecen_entre_los_impuestos_de_linea(): void
    {
        $retencion = $this->crearImpuesto('income_withholding', 2.5);
        $iva = $this->crearImpuesto('vat', 19);

        foreach (['sale', 'purchase'] as $aplica) {
            TaxOptions::forget();
            $impuestos = TaxOptions::taxes($aplica);

            $this->assertArrayHasKey($iva->id, $impuestos, "El IVA debe estar disponible en {$aplica}.");
            $this->assertArrayNotHasKey($retencion->id, $impuestos,
                "La retención no debe ofrecerse como impuesto de línea en {$aplica}.");

            TaxOptions::forget();
            $retenciones = TaxOptions::retentions($aplica);

            $this->assertArrayHasKey($retencion->id, $retenciones);
            $this->assertArrayNotHasKey($iva->id, $retenciones,
                'El IVA no es una retención.');
        }
    }

    /** Un impuesto de otra empresa no puede aparecer en mi factura. */
    public function test_las_opciones_no_cruzan_empresas(): void
    {
        $ajeno = Tax::withoutGlobalScopes()->create([
            'company_id' => Company::query()->where('id', '!=', $this->company->id)->value('id')
                ?? $this->company->id,
            'code' => 'ZZAJENO'.random_int(100, 999),
            'name' => 'ZZ Impuesto de otra empresa',
            'type' => 'vat',
            'applies_to' => 'both',
            'rate' => 5,
            'is_active' => true,
        ]);
        $this->limpiar[] = fn () => Tax::withoutGlobalScopes()->whereKey($ajeno->id)->forceDelete();

        if ($ajeno->company_id === $this->company->id) {
            $this->markTestSkipped('Solo hay una empresa en la base: no hay con qué cruzar.');
        }

        $this->assertArrayNotHasKey($ajeno->id, TaxOptions::taxes('sale'));
        $this->assertArrayNotHasKey($ajeno->id, TaxOptions::taxes('purchase'));
    }

    /**
     * La tarifa se guarda con cuatro decimales. Sin recortar, el IVA se leía
     * «IVA-19 (19.0000%)» y no cabía en la columna.
     */
    public function test_la_tarifa_se_muestra_sin_ceros_de_relleno(): void
    {
        $iva = $this->crearImpuesto('vat', 19);
        $ica = $this->crearImpuesto('ica_withholding', 0.414);

        $this->assertSame('19', TaxOptions::rate($iva));
        $this->assertSame('0,414', TaxOptions::rate($ica));
        $this->assertStringContainsString('19%', TaxOptions::shortLabel($iva));
        $this->assertStringNotContainsString('0000', TaxOptions::longLabel($ica));
    }

    /** El selector de productos trae una primera tanda sin escribir nada. */
    public function test_los_productos_vienen_precargados_y_la_busqueda_sigue_funcionando(): void
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZSEL'.random_int(10000, 99999),
            'name' => 'ZZ Producto buscable '.random_int(1000, 9999),
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'is_purchasable' => true,
            'default_sale_price' => 1000,
            'default_purchase_price' => 500,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();

        ProductOptions::forget();

        $this->assertNotEmpty(ProductOptions::initial('sale'),
            'El selector se abriría vacío y habría que adivinar el nombre.');
        $this->assertLessThanOrEqual(ProductOptions::PRECARGADOS, count(ProductOptions::initial('sale')));

        $encontrado = ProductOptions::search('sale', $producto->code);
        $this->assertArrayHasKey($producto->id, $encontrado,
            'La búsqueda debe seguir llegando a toda la base, no solo a la precarga.');

        $this->assertSame($producto->code.' — '.$producto->name, ProductOptions::label($producto->id));
    }

    /** Buscar con la caja vacía devuelve la precarga, no un listado vacío. */
    public function test_una_busqueda_vacia_devuelve_la_precarga(): void
    {
        $this->assertSame(ProductOptions::initial('sale'), ProductOptions::search('sale', '   '));
    }

    private function crearImpuesto(string $tipo, float $tarifa): Tax
    {
        $tax = Tax::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZ'.strtoupper(substr($tipo, 0, 3)).random_int(1000, 9999),
            'name' => 'ZZ Impuesto '.$tipo,
            'type' => $tipo,
            'applies_to' => 'both',
            'rate' => $tarifa,
            'is_active' => true,
        ]);

        $this->limpiar[] = function () use ($tax) {
            DB::table('taxes')->where('id', $tax->id)->delete();
        };

        TaxOptions::forget();

        return $tax;
    }
}
