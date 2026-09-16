<?php

namespace Tests\Feature;

use App\Filament\App\Pages\OrderTaking\NewOrder;
use App\Models\Company;
use App\Models\Location;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\PriceList;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\ThirdPartyBranch;
use App\Models\User;
use App\Services\Sales\CustomerCreditGuard;
use App\Support\CurrentCompany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Clientes con varias sucursales bajo el mismo NIT.
 *
 * Hay cadenas y distribuidores que reciben en varias direcciones y facturan todo
 * con un solo NIT. El índice único de terceros no deja repetir el documento, y
 * hace bien: duplicar el tercero con un documento inventado —«900123456-1»—
 * parte la cartera, rompe el cupo de crédito y hace que el estado de cuenta no
 * cuadre con lo que el cliente cree deber.
 *
 * Así que la sucursal no es un cliente: es una dirección de entrega con datos
 * comerciales propios, colgando del único tercero que la DIAN conoce.
 *
 * Lo que más cuidan estas pruebas es que **quien no use sucursales no note
 * nada**. Una función que solo sirve a unos clientes no puede cambiarle la
 * pantalla a todos los demás.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ThirdPartyBranchTest extends TestCase
{
    private Company $company;

    private Location $sede;

    private ThirdParty $cadena;

    private ThirdParty $clienteSimple;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->activarTomaDePedidos();

        $this->cadena = $this->cliente('ZZSUC CADENA XYZ');
        $this->clienteSimple = $this->cliente('ZZSUC CLIENTE SIMPLE');
    }

    /**
     * Toma de pedidos es un módulo opcional y la empresa de desarrollo lo tiene
     * apagado. Sin encenderlo la pantalla ni siquiera es accesible y las pruebas
     * fallarían por una razón que no tiene que ver con lo que quieren medir.
     */
    private function activarTomaDePedidos(): void
    {
        $original = $this->company->active_modules ?? [];

        if (! in_array('order_taking', $original, true)) {
            $this->company->update(['active_modules' => [...$original, 'order_taking']]);
            app(CurrentCompany::class)->set($this->company->fresh());

            $this->limpiar[] = function () use ($original) {
                $this->company->update(['active_modules' => $original]);
            };
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------------- el modelo

    /** Varias sucursales cuelgan del mismo NIT. */
    public function test_un_nit_puede_tener_varias_sucursales(): void
    {
        $this->sucursal($this->cadena, 'CEDI Norte', 'N-01');
        $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        $this->assertSame(2, $this->cadena->fresh()->branches()->count());
        $this->assertTrue($this->cadena->fresh()->hasBranches());
    }

    /** Y el tercero sigue siendo uno solo: el documento no se duplica. */
    public function test_el_tercero_sigue_siendo_uno_solo(): void
    {
        $this->sucursal($this->cadena, 'CEDI Norte', 'N-01');
        $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        $mismos = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('document_number', $this->cadena->document_number)
            ->count();

        $this->assertSame(1, $mismos,
            'Duplicar el tercero partiría su cartera y su cupo en pedazos que no suman.');
    }

    /** Dos sucursales del mismo cliente no pueden compartir código. */
    public function test_el_codigo_no_se_repite_en_el_mismo_cliente(): void
    {
        $this->sucursal($this->cadena, 'CEDI Norte', 'N-01');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->sucursal($this->cadena, 'Otra con el mismo código', 'N-01');
    }

    /** Un cliente sin sucursales no tiene ninguna. */
    public function test_un_cliente_normal_no_tiene_sucursales(): void
    {
        $this->assertFalse($this->clienteSimple->hasBranches());
    }

    // ------------------------------------------------------- la herencia

    /** Sin lista propia, la sucursal usa la del cliente. */
    public function test_sin_lista_propia_hereda_la_del_cliente(): void
    {
        $lista = $this->listaDePrecios('ZZSUC MAYORISTA');
        $this->cadena->update(['default_price_list_id' => $lista->id]);

        $sucursal = $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        $this->assertSame($lista->id, $sucursal->priceListId(),
            'Vacío significa «lo que diga el tercero».');
    }

    /** Con lista propia, manda la suya. */
    public function test_con_lista_propia_manda_la_de_la_sucursal(): void
    {
        $delCliente = $this->listaDePrecios('ZZSUC RETAIL');
        $deLaSucursal = $this->listaDePrecios('ZZSUC CEDI');

        $this->cadena->update(['default_price_list_id' => $delCliente->id]);

        $sucursal = $this->sucursal($this->cadena, 'CEDI Norte', 'N-01', [
            'default_price_list_id' => $deLaSucursal->id,
        ]);

        $this->assertSame($deLaSucursal->id, $sucursal->priceListId());
    }

    /**
     * Un cupo nulo hereda; un cupo en cero no.
     *
     * Es la distinción que más fácil se pierde, y la que más caro sale: vacío es
     * «usa el del NIT», cero es «esta sucursal no compra a crédito».
     */
    public function test_cupo_nulo_hereda_pero_cupo_cero_no(): void
    {
        $hereda = $this->sucursal($this->cadena, 'Hereda', 'H-01');
        $sinCredito = $this->sucursal($this->cadena, 'Sin crédito', 'X-01', ['credit_limit' => 0]);

        $this->assertNull($hereda->creditLimit(), 'Nulo significa que el control queda en el NIT.');
        $this->assertSame(0.0, $sinCredito->creditLimit(), 'Cero es un límite real de cero.');
    }

    // ------------------------------------------------- toma de pedidos

    /**
     * El cliente con sucursales pide elegir una.
     *
     * El carrito va con una línea a propósito: sin ella `saveOrder()` corta
     * antes por «agrega al menos una línea» y la prueba pasaría sin haber
     * llegado nunca a la validación que quiere medir.
     */
    public function test_el_pedido_exige_sucursal_si_el_cliente_las_tiene(): void
    {
        $this->sucursal($this->cadena, 'CEDI Norte', 'N-01');
        $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        $antes = Order::query()->where('company_id', $this->company->id)->count();

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cadena->id)
            ->set('branchId', null)
            ->set('cart', [$this->lineaDeCarrito()])
            ->call('saveOrder')
            ->assertNotified('Falta la sucursal');

        $this->assertSame($antes, Order::query()->where('company_id', $this->company->id)->count(),
            'Sin sucursal el pedido sale sin dirección de entrega y alguien lo descubre '
            .'con el camión cargado.');
    }

    /** Elegida la sucursal, el pedido sí sale. */
    public function test_con_la_sucursal_elegida_el_pedido_sale(): void
    {
        $sucursal = $this->sucursal($this->cadena, 'CEDI Norte', 'N-01');
        $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cadena->id)
            ->set('branchId', $sucursal->id)
            ->set('cart', [$this->lineaDeCarrito()])
            ->call('saveOrder')
            ->assertNotNotified('Falta la sucursal');

        $pedido = Order::query()
            ->where('company_id', $this->company->id)
            ->where('third_party_id', $this->cadena->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($pedido, 'El pedido debió crearse.');
        $this->assertSame($sucursal->id, $pedido->third_party_branch_id,
            'Sin guardar la sucursal, el despacho no sabe a dónde ir.');

        $this->limpiar[] = function () use ($pedido) {
            DB::table('order_taking_order_items')->where('order_id', $pedido->id)->delete();
            DB::table('order_taking_orders')->where('id', $pedido->id)->delete();
        };
    }

    /** Con una sola sucursal no hay nada que preguntar: se elige sola. */
    public function test_una_sola_sucursal_se_elige_sola(): void
    {
        $sucursal = $this->sucursal($this->cadena, 'Única', 'U-01');

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cadena->id)
            ->assertSet('branchId', $sucursal->id);
    }

    /** Cambiar de cliente borra la sucursal del anterior. */
    public function test_cambiar_de_cliente_borra_la_sucursal(): void
    {
        $this->sucursal($this->cadena, 'CEDI Norte', 'N-01');
        $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cadena->id)
            ->set('branchId', $this->cadena->branches()->first()->id)
            ->set('customerId', $this->clienteSimple->id)
            ->assertSet('branchId', null,
                'Dejarla puesta despacharía el pedido a la dirección de otro cliente.');
    }

    /** Elegir sucursal aplica su lista de precios. */
    public function test_elegir_sucursal_aplica_su_lista_de_precios(): void
    {
        $delCliente = $this->listaDePrecios('ZZSUC RETAIL');
        $deLaSucursal = $this->listaDePrecios('ZZSUC CEDI');

        $this->cadena->update(['default_price_list_id' => $delCliente->id]);

        $cedi = $this->sucursal($this->cadena, 'CEDI Norte', 'N-01', [
            'default_price_list_id' => $deLaSucursal->id,
        ]);
        $this->sucursal($this->cadena, 'Punto Sur', 'S-01');

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->cadena->id)
            ->set('branchId', $cedi->id)
            ->assertSet('priceListId', $deLaSucursal->id);
    }

    /**
     * Un cliente sin sucursales sigue funcionando exactamente igual.
     *
     * Es la prueba que protege a los otros 228 clientes: una función que sirve a
     * unos pocos no puede cambiarle la pantalla a todos.
     */
    public function test_un_cliente_sin_sucursales_no_cambia_en_nada(): void
    {
        $lista = $this->listaDePrecios('ZZSUC NORMAL');
        $this->clienteSimple->update(['default_price_list_id' => $lista->id]);

        Livewire::test(NewOrder::class)
            ->set('customerId', $this->clienteSimple->id)
            ->assertSet('branchId', null)
            ->assertSet('priceListId', $lista->id)
            ->assertOk();
    }

    // ----------------------------------------------------------- el cupo

    /** La factura de una sucursal no puede pasar su sub-cupo. */
    public function test_la_sucursal_no_puede_pasar_su_sub_cupo(): void
    {
        $this->cadena->update(['credit_limit' => 50000000]);
        $sucursal = $this->sucursal($this->cadena, 'Punto Sur', 'S-01', ['credit_limit' => 1000000]);

        $factura = $this->facturaPendiente($sucursal, 1500000);

        try {
            app(CustomerCreditGuard::class)->assertWithinLimit($factura);
            $this->fail('Debió cortar: la sucursal se pasa de su cupo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Punto Sur', $e->getMessage(),
                'El mensaje tiene que apuntar a la sucursal, no al NIT.');
        }
    }

    /** Dentro de su sub-cupo, pasa. */
    public function test_dentro_del_sub_cupo_la_venta_pasa(): void
    {
        $this->cadena->update(['credit_limit' => 50000000]);
        $sucursal = $this->sucursal($this->cadena, 'Punto Sur', 'S-01', ['credit_limit' => 1000000]);

        $factura = $this->facturaPendiente($sucursal, 800000);

        app(CustomerCreditGuard::class)->assertWithinLimit($factura);

        $this->assertTrue(true);
    }

    /**
     * El cupo del NIT sigue siendo el techo.
     *
     * Una sucursal con cupo holgado no puede saltarse el límite del cliente:
     * sumar los sub-cupos le daría más crédito del que se le aprobó.
     */
    public function test_el_cupo_del_nit_sigue_siendo_el_techo(): void
    {
        $this->cadena->update(['credit_limit' => 1000000]);
        $sucursal = $this->sucursal($this->cadena, 'CEDI Norte', 'N-01', ['credit_limit' => 90000000]);

        $factura = $this->facturaPendiente($sucursal, 5000000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cupo es de/');

        app(CustomerCreditGuard::class)->assertWithinLimit($factura);
    }

    /** Sin cupo propio, la sucursal solo responde ante el del NIT. */
    public function test_sin_cupo_propio_solo_manda_el_del_nit(): void
    {
        $this->cadena->update(['credit_limit' => 10000000]);
        $sucursal = $this->sucursal($this->cadena, 'Hereda', 'H-01');

        $factura = $this->facturaPendiente($sucursal, 2000000);

        app(CustomerCreditGuard::class)->assertWithinLimit($factura);

        $this->assertTrue(true);
    }

    // --------------------------------------------------------- auxiliares

    /** Una línea cualquiera, solo para que el carrito no esté vacío. */
    private function lineaDeCarrito(): array
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZSUC'.random_int(10000, 99999),
            'name' => 'ZZ Producto sucursales',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 10000,
            'default_purchase_price' => 5000,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()
            ->whereKey($producto->id)->forceDelete();

        return [
            'product_id' => $producto->id,
            'code' => $producto->code,
            'name' => $producto->name,
            'quantity' => 1,
            'price_before_tax' => 10000,
            'tax_amount' => 0,
            'price_at_public' => 10000,
        ];
    }

    private function facturaPendiente(ThirdPartyBranch $sucursal, float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $sucursal->third_party_id,
            'third_party_branch_id' => $sucursal->id,
            'prefix' => 'ZZSUC',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura->fresh(['customer', 'branch']);
    }

    private function sucursal(ThirdParty $cliente, string $nombre, ?string $codigo, array $extra = []): ThirdPartyBranch
    {
        $sucursal = ThirdPartyBranch::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'third_party_id' => $cliente->id,
            'code' => $codigo,
            'name' => $nombre,
            'address' => 'Cra 45 #120-30',
            'city' => 'Bogotá',
            'active' => true,
            ...$extra,
        ]);

        $this->limpiar[] = fn () => DB::table('third_party_branches')->where('id', $sucursal->id)->delete();

        return $sucursal->fresh(['thirdParty']);
    }

    private function listaDePrecios(string $nombre): PriceList
    {
        $lista = PriceList::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => $nombre,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('order_taking_price_lists')->where('id', $lista->id)->delete();

        return $lista;
    }

    private function cliente(string $nombre): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => $nombre,
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($cliente) {
            DB::table('third_party_branches')->where('third_party_id', $cliente->id)->delete();
            ThirdParty::withoutGlobalScopes()->whereKey($cliente->id)->forceDelete();
        };

        return $cliente;
    }
}
