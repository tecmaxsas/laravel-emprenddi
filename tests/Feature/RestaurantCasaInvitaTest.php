<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Inventory\InventoryEngine;
use App\Services\Restaurant\RestaurantOrderEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Casa invita»: cerrar una cuenta sin cobrarla.
 *
 * El negocio regala el consumo, así que **no entra plata**: no hay factura ni
 * movimiento de caja. Pero **la comida sí salió de la cocina**, y hasta ahora
 * el sistema seguía creyendo que esos platos estaban en bodega: el inventario
 * se descargaba al facturar, y aquí no se factura.
 *
 * El faltante aparecía al contar, semanas después y sin forma de saber de
 * dónde salió.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class RestaurantCasaInvitaTest extends TestCase
{
    private Company $company;

    private User $user;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------- el inventario SÍ sale

    /** Cerrar sin facturar descarga lo consumido. */
    public function test_casa_invita_descarga_el_inventario(): void
    {
        $plato = $this->productoConStock('ZZCI-PLATO', 20);
        $orden = $this->ordenCon($plato, 3);

        app(RestaurantOrderEngine::class)->close($orden, 'Casa invita — prueba');

        $this->assertSame(17.0, $this->stockDe($plato),
            'La comida salió de la cocina aunque no se cobre.');
    }

    /** Un ítem cancelado no se descarga: nunca se preparó. */
    public function test_un_item_cancelado_no_se_descarga(): void
    {
        $plato = $this->productoConStock('ZZCI-PLATO2', 20);
        $orden = $this->ordenCon($plato, 2);

        $orden->items()->update(['kitchen_status' => OrderItem::KS_CANCELLED]);

        app(RestaurantOrderEngine::class)->close($orden->fresh(), 'Casa invita');

        $this->assertSame(20.0, $this->stockDe($plato));
    }

    /**
     * Sin stock, la cuenta se cierra igual y el saldo queda en negativo.
     *
     * Negarse aquí dejaría la mesa ocupada y al cliente esperando por un
     * saldo mal cuadrado, cuando la comida ya se la comieron. El negativo es
     * la señal honesta de que hay que contar.
     */
    public function test_sin_stock_la_cuenta_se_cierra_igual(): void
    {
        $plato = $this->productoConStock('ZZCI-PLATO3', 1);
        $orden = $this->ordenCon($plato, 4);

        app(RestaurantOrderEngine::class)->close($orden, 'Casa invita');

        $this->assertSame(-3.0, $this->stockDe($plato));
        $this->assertSame(Order::STATUS_CLOSED, $orden->fresh()->status);
    }

    // ------------------------------------------------- la caja NO se entera

    /** No se crea factura. */
    public function test_casa_invita_no_genera_factura(): void
    {
        $plato = $this->productoConStock('ZZCI-PLATO4', 10);
        $orden = $this->ordenCon($plato, 1);

        $antes = SaleInvoice::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->count();

        app(RestaurantOrderEngine::class)->close($orden, 'Casa invita');

        $this->assertSame($antes, SaleInvoice::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->count(),
            'El negocio regaló el consumo: no hay venta que facturar.');
    }

    /** Y el turno de caja no cambia ni un peso. */
    public function test_casa_invita_no_toca_la_caja(): void
    {
        $turno = $this->abrirCaja();
        $resumen = app(CashSessionSummary::class);

        $antes = $resumen->compute($turno->fresh());

        $plato = $this->productoConStock('ZZCI-PLATO5', 10);
        $orden = $this->ordenCon($plato, 2, $turno->id);

        app(RestaurantOrderEngine::class)->close($orden, 'Casa invita');

        $despues = $resumen->compute($turno->fresh());

        $this->assertSame($antes['expected_cash'], $despues['expected_cash'],
            'Una cortesía no pone ni quita billetes del cajón.');
        $this->assertSame($antes['sales']['total'], $despues['sales']['total']);
    }

    /** El movimiento queda rastreable hasta la orden. */
    public function test_el_movimiento_dice_de_donde_salio(): void
    {
        $plato = $this->productoConStock('ZZCI-PLATO6', 10);
        $orden = $this->ordenCon($plato, 1);

        app(RestaurantOrderEngine::class)->close($orden, 'Casa invita');

        $movimiento = InventoryMovement::withoutGlobalScopes()
            ->where('product_id', $plato->id)
            ->where('reference_type', Order::class)
            ->where('reference_id', $orden->id)
            ->first();

        $this->assertNotNull($movimiento,
            'Sin referencia a la orden, el faltante no se puede explicar después.');
        $this->assertStringContainsString('Casa invita', (string) $movimiento->description);
    }

    // --------------------------------------------------------- auxiliares

    private function productoConStock(string $codigo, float $cantidad): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ '.$codigo,
            'type' => 'good',
            'active' => true,
            'is_sellable' => true,
            'track_inventory' => true,
            'default_sale_price' => 15000,
            'default_purchase_price' => 6000,
        ]);

        $this->limpiar[] = function () use ($producto) {
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            DB::table('product_locations')->where('product_id', $producto->id)->delete();
            DB::table('products')->where('id', $producto->id)->delete();
        };

        app(InventoryEngine::class)->addMovement($producto, $this->sede, [
            'type' => 'opening',
            'quantity' => $cantidad,
            'unit_cost' => 6000,
            'date' => now()->subDay(),
            'description' => 'ZZ saldo de prueba',
        ]);

        return $producto;
    }

    private function ordenCon(Product $producto, float $cantidad, ?int $turnoId = null): Order
    {
        $orden = Order::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cash_register_session_id' => $turnoId,
            'prefix' => 'ZZCI',
            'number' => random_int(900000, 999999),
            'status' => Order::STATUS_OPEN,
            'opened_at' => now(),
            'server_user_id' => $this->user->id,
            'subtotal' => 15000 * $cantidad,
            'discount_total' => 0,
            'tax_total' => 0,
            'tip_amount' => 0,
            'total' => 15000 * $cantidad,
        ]);

        $this->limpiar[] = function () use ($orden) {
            DB::table('restaurant_order_items')->where('restaurant_order_id', $orden->id)->delete();
            DB::table('restaurant_orders')->where('id', $orden->id)->delete();
        };

        OrderItem::create([
            'restaurant_order_id' => $orden->id,
            'line_number' => 1,
            'product_id' => $producto->id,
            'description' => $producto->name,
            'quantity' => $cantidad,
            'unit_price' => 15000,
            'subtotal' => 15000 * $cantidad,
            'total' => 15000 * $cantidad,
            'kitchen_status' => OrderItem::KS_SERVED,
        ]);

        return $orden->fresh(['items']);
    }

    private function abrirCaja(): CashRegisterSession
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHour(),
            'opening_amount' => 50000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function stockDe(Product $producto): float
    {
        return (float) InventoryMovement::withoutGlobalScopes()
            ->where('product_id', $producto->id)
            ->where('location_id', $this->sede->id)
            ->orderByDesc('id')
            ->value('balance_quantity_after');
    }
}
