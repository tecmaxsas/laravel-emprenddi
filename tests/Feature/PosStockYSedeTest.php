<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Support\CurrentCompany;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Lo que el POS sabe del inventario, y quién elige la sede.
 *
 * **El stock.** El cajero vendía a ciegas: el POS no decía cuánto hay, así que
 * el «no me queda» aparecía con el cliente ya en la caja —o peor, la venta
 * pasaba y el faltante salía semanas después en el conteo, cuando ya nadie
 * sabía en cuál venta fue—.
 *
 * **La sede.** Elegirla decide de qué bodega sale el inventario del turno y a
 * qué arqueo van sus ventas. Un cajero que se equivoca descarga stock de otra
 * sede y le deja el descuadre a alguien más, en un sitio donde nadie va a
 * buscar la causa.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PosStockYSedeTest extends TestCase
{
    use FuerzaPosDeRetail;

    private Company $empresa;

    private User $usuario;

    private Location $sede;

    private Product $producto;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);

        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->fuerzaPosDeRetail($this->empresa, $this->limpiar);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZSTK'.random_int(10000, 99999),
            'name' => 'ZZSTK Producto stock',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => true,
            'is_sellable' => true,
            'default_sale_price' => 10000,
            'default_purchase_price' => 5000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('inventory_movements')->where('product_id', $this->producto->id)->delete();
            DB::table('product_locations')->where('product_id', $this->producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
        };
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /* ------------------------------------------------------------------ */
    /*  Stock */
    /* ------------------------------------------------------------------ */

    /**
     * El stock en bloque dice lo mismo que el de a uno.
     *
     * Es la regla que importa: una grilla que diga 3 cuando el motor dice 0
     * es peor que no mostrar nada, porque se le cree.
     */
    public function test_el_stock_en_bloque_coincide_con_el_de_a_uno(): void
    {
        $this->entrada(7);

        $motor = app(InventoryEngine::class);

        $unoAUno = $motor->currentStock($this->producto->id, $this->sede->id);
        $enBloque = $motor->stockForProducts([$this->producto->id], $this->sede->id);

        $this->assertEqualsWithDelta(7, $unoAUno, 0.001);
        $this->assertEqualsWithDelta($unoAUno, $enBloque[$this->producto->id], 0.001);
    }

    /** Un producto sin movimientos responde cero, no «no sé». */
    public function test_un_producto_sin_movimientos_responde_cero(): void
    {
        $stock = app(InventoryEngine::class)
            ->stockForProducts([$this->producto->id], $this->sede->id);

        $this->assertArrayHasKey($this->producto->id, $stock,
            'Sin la clave, quien consulta no distingue cero de «no pregunté».');
        $this->assertEqualsWithDelta(0, $stock[$this->producto->id], 0.001);
    }

    /** Pedir el stock de nada no consulta nada. */
    public function test_sin_productos_no_hay_consulta(): void
    {
        $this->assertSame([], app(InventoryEngine::class)->stockForProducts([], $this->sede->id));
    }

    /** El POS avisa cuando el carrito se pasa de la existencia. */
    public function test_el_pos_avisa_cuando_el_carrito_supera_el_stock(): void
    {
        $this->entrada(2);
        $turno = $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $this->producto->id)
            ->call('addProductToCart', $this->producto->id)
            ->call('addProductToCart', $this->producto->id);

        $excesos = $componente->instance()->excesosDeStock;

        $this->assertArrayHasKey($this->producto->id, $excesos,
            'Tres unidades de un producto con dos en stock tienen que avisar.');
        $this->assertEqualsWithDelta(3, $excesos[$this->producto->id]['pedido'], 0.001);
        $this->assertEqualsWithDelta(2, $excesos[$this->producto->id]['hay'], 0.001);

        unset($turno);
    }

    /** Y no avisa cuando alcanza. */
    public function test_no_avisa_cuando_el_stock_alcanza(): void
    {
        $this->entrada(10);
        $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $this->producto->id);

        $this->assertSame([], $componente->instance()->excesosDeStock);
    }

    /**
     * El aviso suma las líneas del mismo producto.
     *
     * Comparar contra la línea recién agregada en vez de contra el carrito
     * dejaría pasar dos líneas de tres unidades cuando solo hay cuatro.
     */
    public function test_el_aviso_suma_todo_el_carrito(): void
    {
        $this->entrada(4);
        $this->turnoAbierto();

        $componente = Livewire::test(PosTerminal::class)
            ->call('addProductToCart', $this->producto->id);

        $cantidades = $componente->instance()->cantidadEnCarrito;

        $this->assertSame([$this->producto->id => 1.0], array_map('floatval', $cantidades));
    }

    /* ------------------------------------------------------------------ */
    /*  Sede */
    /* ------------------------------------------------------------------ */

    /** El administrador puede elegir sede al abrir caja. */
    public function test_el_administrador_puede_elegir_sede(): void
    {
        $componente = Livewire::test(PosTerminal::class);

        $this->assertTrue($componente->instance()->puedeElegirSede,
            'El usuario de la prueba es admin.');
    }

    /**
     * Al que no puede elegir se le impone la sede aunque mande otra.
     *
     * Ocultar el selector no alcanza: una propiedad de Livewire se puede
     * escribir desde el navegador.
     */
    public function test_al_que_no_puede_elegir_se_le_impone_la_sede(): void
    {
        $otra = Location::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZSTKLOC'.random_int(1000, 9999),
            'name' => 'ZZSTK Bodega lejana',
            'is_main' => false,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('locations')->where('id', $otra->id)->delete();

        // Un usuario PROPIO, cajero, en vez de cambiarle el rol al usuario
        // compartido de la base de desarrollo.
        //
        // La primera version hacia lo segundo y salio cara: los roles son por
        // empresa pero conviven con las PLANTILLAS, que se llaman igual, asi
        // que restaurar por nombre engancho al admin a la plantilla. Y aun
        // restaurando por id, una prueba que le cambia el rol al usuario que
        // usan las otras ochocientas es una prueba que puede romper cualquier
        // cosa que corra despues. El unico arreglo que cierra la categoria es
        // no tocarlo.
        $cajeroRol = Role::query()
            ->where('company_id', $this->empresa->id)
            ->where('name', 'CAJERO')
            ->first();

        $this->assertNotNull($cajeroRol, 'La empresa de pruebas necesita un rol CAJERO propio.');

        $cajero = User::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'ZZSTK Cajero',
            'email' => 'zzstk'.random_int(100000, 999999).'@pruebas.local',
            'password' => 'no-se-usa-'.random_int(1000, 9999),
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($cajero) {
            DB::table('model_has_roles')->where('model_id', $cajero->id)
                ->where('model_type', User::class)->delete();
            DB::table('users')->where('id', $cajero->id)->delete();
        };

        $cajero->assignRole($cajeroRol);

        $this->actingAs($cajero);

        $this->cerrarTurnosAbiertos();

        $componente = Livewire::test(PosTerminal::class)
            ->set('openingLocationId', $otra->id)
            ->set('openingAmount', 0)
            ->call('openCashSession');

        $sesion = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('cashier_user_id', $cajero->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('id')->first();

        if ($sesion) {
            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $sesion->id)->delete();
        }

        $this->assertFalse($componente->instance()->puedeElegirSede);
        $this->assertNotNull($sesion, 'La caja tenía que abrir igual.');
        $this->assertNotEquals($otra->id, $sesion->location_id,
            'Mandó una sede que no puede elegir: se le impone la de por defecto.');
    }

    /* ------------------------------------------------------------------ */
    /*  Ajuste */
    /* ------------------------------------------------------------------ */

    /** El saldo del cliente en el tiquete está apagado por defecto. */
    public function test_el_saldo_del_cliente_viene_apagado(): void
    {
        $this->assertFalse(PosSettings::showsCustomerBalance($this->empresa->id),
            'Imprimir lo que alguien debe lo deja a la vista de quien esté detrás en el mostrador.');
    }

    // --------------------------------------------------------- auxiliares

    private function entrada(float $cantidad): void
    {
        app(InventoryEngine::class)->addMovement(
            $this->producto,
            $this->sede,
            [
                'type' => 'adjustment_in',
                'quantity' => $cantidad,
                'unit_cost' => 5000,
                'date' => now()->toDateString(),
                'description' => 'ZZSTK carga de prueba',
            ],
        );
    }

    private function cerrarTurnosAbiertos(): void
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isEmpty()) {
            return;
        }

        DB::table('cash_register_sessions')->whereIn('id', $previos)
            ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
            ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
    }

    private function turnoAbierto(): CashRegisterSession
    {
        $this->cerrarTurnosAbiertos();

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->usuario->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }
}
