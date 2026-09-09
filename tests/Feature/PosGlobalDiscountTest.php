<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\PosDestination;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El descuento global del POS.
 *
 * Tenía cuatro problemas, y los cuatro se sienten en caja:
 *
 *  1. Vaciar el campo reventaba la pantalla con un TypeError. El navegador
 *     manda la cadena vacía y el método exigía un float.
 *  2. `resetCart()` no lo limpiaba: el siguiente cliente heredaba el descuento
 *     del anterior sin que el cajero lo viera.
 *  3. Un descuento de $50.000 dejaba de valer $50.000 en cuanto se agregaba
 *     otro producto, porque el monto se convertía a porcentaje una sola vez.
 *  4. Sumaba los porcentajes en vez de aplicar el global sobre la base ya neta
 *     del descuento de línea, así que el POS y la pantalla de facturas daban
 *     resultados distintos con los mismos datos.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PosGlobalDiscountTest extends TestCase
{
    private Company $company;

    private Product $producto;

    private Product $otro;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Hay que dar con una empresa cuyo POS sea el de retail: en una de
        // restaurante o parqueadero, PosTerminal redirige en mount() y el
        // componente ni siquiera llega a montarse.
        $user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(function (User $u) {
                if (! $u->can('pos.use')) {
                    return false;
                }

                $this->actingAs($u);
                app(CurrentCompany::class)->set(Company::find($u->company_id));

                $destino = PosDestination::resolve();

                return $destino === null || $destino === PosDestination::RETAIL;
            })
            ?? $this->markTestSkipped('Ninguna empresa de la base usa el POS de retail.');

        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->producto = $this->crearProducto('ZZ Producto POS A', 100000, 19);
        $this->otro = $this->crearProducto('ZZ Producto POS B', 50000, 0);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** El fallo que reventaba el POS: borrar el campo del descuento. */
    public function test_vaciar_el_campo_no_revienta_la_pantalla(): void
    {
        $pos = $this->conCarrito();

        // Así llega del navegador cuando el cajero borra lo que había escrito.
        $pos->call('setCartDiscount', 'pct', '')->assertOk();
        $pos->call('setCartDiscount', 'amount', '')->assertOk();
        $pos->call('setCartDiscount', 'pct', 'abc')->assertOk();

        $this->assertEqualsWithDelta(0, $pos->get('cartDiscountValue'), 0.01);
        $this->assertEqualsWithDelta(0, $this->totales($pos)['discount'], 0.01);
    }

    /** El valor llega como texto del input y tiene que entenderse igual. */
    public function test_un_porcentaje_escrito_como_texto_se_aplica(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setCartDiscount', 'pct', '10');

        // 100.000 con 10 % → base 90.000, IVA 19 % = 17.100.
        $totales = $this->totales($pos);
        $this->assertEqualsWithDelta(10000, $totales['discount'], 0.01);
        $this->assertEqualsWithDelta(17100, $totales['tax'], 0.01);
        $this->assertEqualsWithDelta(107100, $totales['total'], 0.01);
    }

    /** El IVA se calcula sobre la base ya descontada, no sobre el total. */
    public function test_el_iva_sale_sobre_la_base_descontada(): void
    {
        $pos = $this->conCarrito();
        $pos->call('setCartDiscount', 'pct', 10);

        $this->assertEqualsWithDelta(17100, $this->totales($pos)['tax'], 0.01,
            'Sobre el total serían 19.000: $1.900 de IVA de más en esta venta.');
    }

    /**
     * Un descuento de $20.000 tiene que seguir valiendo $20.000 cuando entra
     * otro producto al carrito.
     */
    public function test_un_monto_fijo_no_se_desajusta_al_cambiar_el_carrito(): void
    {
        $pos = $this->conCarrito();
        $pos->call('setCartDiscount', 'amount', 20000);

        $this->assertEqualsWithDelta(20000, $this->totales($pos)['discount'], 0.01);

        $pos->call('addProductToCart', $this->otro->id);

        $this->assertEqualsWithDelta(20000, $this->totales($pos)['discount'], 0.01,
            'Antes el monto se convertía a porcentaje una vez y crecía con el carrito.');
    }

    /**
     * Un 10 % de línea más un 10 % global es un 19 % de descuento, no un 20 %:
     * el global va sobre lo que quedó. Es lo que hacen las facturas.
     */
    public function test_el_global_va_sobre_la_base_ya_neta_del_descuento_de_linea(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setLineDiscountPct', 0, 10);
        $pos->call('setCartDiscount', 'pct', 10);

        // 100.000 − 10 % = 90.000; 10 % global = 9.000. Total descontado 19.000.
        $this->assertEqualsWithDelta(19000, $this->totales($pos)['discount'], 0.01,
            'Sumar los porcentajes daría 20.000 y no coincidiría con las facturas.');
    }

    /** El reparto respeta la tarifa de cada línea. */
    public function test_el_reparto_respeta_la_tarifa_de_cada_linea(): void
    {
        $pos = $this->conCarrito();
        $pos->call('addProductToCart', $this->otro->id);

        // Carrito: 100.000 al 19 % + 50.000 sin IVA = base 150.000.
        $pos->call('setCartDiscount', 'pct', 10);

        $totales = $this->totales($pos);

        $this->assertEqualsWithDelta(15000, $totales['discount'], 0.01);
        // A la gravada le tocan 10.000 → base 90.000 × 19 % = 17.100.
        $this->assertEqualsWithDelta(17100, $totales['tax'], 0.01);
    }

    /** Quitar el descuento devuelve los totales a como estaban. */
    public function test_quitar_el_descuento_restituye_los_totales(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setCartDiscount', 'pct', 25);
        $this->assertGreaterThan(0, $this->totales($pos)['discount']);

        $pos->call('clearCartDiscount')->assertOk();

        $totales = $this->totales($pos);
        $this->assertEqualsWithDelta(0, $totales['discount'], 0.01);
        $this->assertEqualsWithDelta(19000, $totales['tax'], 0.01);
        $this->assertEqualsWithDelta(119000, $totales['total'], 0.01);
    }

    /** El descuento es de esa venta: no se hereda a la siguiente. */
    public function test_la_venta_siguiente_no_hereda_el_descuento(): void
    {
        $pos = $this->conCarrito();
        $pos->call('setCartDiscount', 'pct', 30);

        $pos->call('resetCart');
        $pos->call('addProductToCart', $this->producto->id);

        $this->assertEqualsWithDelta(0, $pos->get('cartDiscountValue'), 0.01);
        $this->assertEqualsWithDelta(0, $this->totales($pos)['discount'], 0.01,
            'El cliente siguiente estaba recibiendo el descuento del anterior.');
    }

    /** Aplicarlo dos veces no descuenta dos veces. */
    public function test_aplicarlo_dos_veces_no_acumula(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setCartDiscount', 'pct', 10);
        $pos->call('setCartDiscount', 'pct', 10);
        $pos->call('addProductToCart', $this->producto->id);   // fuerza otro recálculo

        // Dos unidades: base 200.000, 10 % = 20.000.
        $this->assertEqualsWithDelta(20000, $this->totales($pos)['discount'], 0.01);
    }

    /**
     * El caso que dejaba ventas en cero: el selector en «%» y un valor en
     * pesos. Recortarlo a 100 % regalaba la mercancía sin un solo aviso.
     */
    public function test_un_porcentaje_imposible_se_rechaza_en_vez_de_recortarse(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setCartDiscount', 'pct', 10000)->assertOk();

        $totales = $this->totales($pos);

        $this->assertEqualsWithDelta(0, $totales['discount'], 0.01,
            'Un 10.000 % es un dedo o un modo equivocado: no se aplica nada.');
        $this->assertEqualsWithDelta(119000, $totales['total'], 0.01,
            'La venta no puede quedar en cero por escribir de más.');
        $this->assertEqualsWithDelta(0, $pos->get('cartDiscountValue'), 0.01);
    }

    /** El 100 % sí es válido: a veces se regala. */
    public function test_el_cien_por_ciento_si_se_aplica(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setCartDiscount', 'pct', 100);

        $this->assertEqualsWithDelta(100000, $this->totales($pos)['discount'], 0.01);
        $this->assertEqualsWithDelta(0, $this->totales($pos)['total'], 0.01);
    }

    /**
     * Cambiar de «%» a «$» vuelve a aplicar lo que ya estaba escrito. Antes el
     * botón solo cambiaba la propiedad y no recalculaba nada.
     */
    public function test_cambiar_de_modo_reaplica_el_valor(): void
    {
        $pos = $this->conCarrito();

        $pos->call('setCartDiscount', 'pct', 10);
        $this->assertEqualsWithDelta(10000, $this->totales($pos)['discount'], 0.01);

        $pos->call('setCartDiscountMode', 'amount');

        // Los mismos 10 ahora son $10, no el 10 %.
        $this->assertEqualsWithDelta(10, $this->totales($pos)['discount'], 0.01);
        $this->assertSame('amount', $pos->get('cartDiscountMode'));
    }

    /** No se puede descontar más de lo que vale el carrito. */
    public function test_no_se_descuenta_mas_que_el_carrito(): void
    {
        $pos = $this->conCarrito();
        $pos->call('setCartDiscount', 'amount', 500000);

        $totales = $this->totales($pos);

        $this->assertEqualsWithDelta(100000, $totales['discount'], 0.01);
        $this->assertEqualsWithDelta(0, $totales['total'], 0.01);
    }

    // ------------------------------------------------------- auxiliares

    private function conCarrito(): Testable
    {
        return Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $this->producto->id);
    }

    /** @return array<string, float> */
    private function totales($pos): array
    {
        return $pos->instance()->totals();
    }

    private function crearProducto(string $nombre, float $precio, float $iva): Product
    {
        // El IVA del carrito sale del impuesto por defecto del producto: sin
        // el impuesto asociado, la tarifa llega en cero y no se prueba nada.
        $impuestoId = null;

        if ($iva > 0) {
            $impuesto = Tax::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'code' => 'ZZIVA'.random_int(1000, 9999),
                'name' => 'ZZ IVA de prueba',
                'type' => 'vat',
                'applies_to' => 'both',
                'rate' => $iva,
                'is_active' => true,
            ]);

            $impuestoId = $impuesto->id;
            $this->limpiar[] = fn () => DB::table('taxes')->where('id', $impuesto->id)->delete();
        }

        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZPOS'.random_int(10000, 99999),
            'name' => $nombre,
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => $precio,
            'default_sale_tax_id' => $impuestoId,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();

        return $producto;
    }
}
