<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Corregir una compra cuya mercancía ya se vendió en parte.
 *
 * Corregir deshacía TODO el inventario y lo volvía a aplicar. El resultado
 * final era el mismo, pero el paso intermedio no: una compra de 6 unidades
 * con 1 ya vendida bajaba a -1 antes de volver a subir, y el motor de
 * inventario —con razón— se negaba. Así que la venta de UNA unidad bloqueaba
 * la corrección de la factura entera, aunque lo que se fuera a cambiar fuera
 * otra línea que nadie había tocado.
 *
 * Ahora el inventario se lleva a la cifra nueva moviendo solo la DIFERENCIA.
 * Una línea que no cambia no genera ningún movimiento, así que no puede
 * fallar. Solo se topa con el inventario quien rebaja una cantidad por debajo
 * de lo que queda en existencia, que es cuando el problema es real — y el
 * mensaje dice cuál línea, no «la factura».
 *
 * Anular es otra cosa: ahí la compra entera deja de existir y la mercancía
 * tiene que salir completa, así que el bloqueo sigue siendo de toda la
 * factura.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CorregirCompraConStockVendidoTest extends TestCase
{
    private Company $empresa;

    private Location $sede;

    private ThirdParty $proveedor;

    private PurchaseInvoiceEngine $motor;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($usuario->company_id);
        $this->actingAs($usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();
        $this->proveedor = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->motor = app(PurchaseInvoiceEngine::class);
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

    /** El caso de la pantalla: entraron 6, se vendió 1, y SÍ se puede corregir. */
    public function test_se_corrige_aunque_ya_se_haya_vendido_parte(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->vender($producto, 1);

        $this->assertNull($this->motor->porQueNoSePuedeEditar($compra->fresh()),
            'Una venta de una unidad no puede bloquear la factura entera.');
    }

    /**
     * Corregir no le quita la mercancía a la bodega.
     *
     * Antes, mientras la compra estaba en borrador para corregirla, su
     * mercancía desaparecía del stock aunque siguiera físicamente en el
     * estante. Si alguien abandonaba la corrección, quedaba así.
     */
    public function test_corregir_no_saca_la_mercancia_del_stock(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->assertEqualsWithDelta(6, $this->stock($producto), 0.01);

        $this->motor->prepararParaEditar($compra->fresh());

        $this->assertEqualsWithDelta(6, $this->stock($producto), 0.01,
            'La mercancía sigue en la bodega mientras se corrige.');
    }

    /** Subir la cantidad entra solo lo que falta. */
    public function test_subir_la_cantidad_entra_solo_la_diferencia(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->vender($producto, 1);
        $this->assertEqualsWithDelta(5, $this->stock($producto), 0.01);

        $this->corregirCantidad($compra, 8);

        $this->assertEqualsWithDelta(7, $this->stock($producto), 0.01,
            'Quedaban 5 y entraron 2 más: 7. Ni 8 ni 13.');
    }

    /** Bajar la cantidad saca solo lo que sobra. */
    public function test_bajar_la_cantidad_saca_solo_la_diferencia(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->vender($producto, 1);

        $this->corregirCantidad($compra, 4);

        $this->assertEqualsWithDelta(3, $this->stock($producto), 0.01,
            'Quedaban 5 y salieron 2: 3.');
    }

    /** Corregir sin tocar cantidades no mueve el inventario en absoluto. */
    public function test_corregir_sin_cambiar_cantidades_no_mueve_nada(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->vender($producto, 1);
        $movimientos = $this->cuantosMovimientos($producto);

        $this->motor->prepararParaEditar($compra->fresh());
        $this->motor->post($compra->fresh(['lines']));

        $this->assertSame($movimientos, $this->cuantosMovimientos($producto),
            'Si la línea no cambió, no hay nada que mover.');
        $this->assertEqualsWithDelta(5, $this->stock($producto), 0.01);
    }

    /**
     * Rebajar por debajo de lo que queda sí se bloquea, y se dice cuál línea.
     *
     * Es el único caso en que el inventario estorba de verdad: entraron 6, se
     * vendieron 5, y se quiere dejar la factura en 0. Habría que sacar 6 y
     * solo queda 1.
     */
    public function test_rebajar_mas_de_lo_que_queda_se_bloquea_por_linea(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->vender($producto, 5);

        $this->motor->prepararParaEditar($compra->fresh());
        PurchaseInvoiceLine::withoutGlobalScopes()
            ->where('purchase_invoice_id', $compra->id)->update(['quantity' => 0.0001]);

        try {
            $this->motor->post($compra->fresh(['lines']));
            $this->fail('Sacar 6 cuando solo queda 1 tiene que fallar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($producto->name, $e->getMessage(),
                'Tiene que decir cuál producto, no «la factura».');
            $this->assertStringContainsString('demás líneas', $e->getMessage(),
                'Y que lo demás sí se puede corregir.');
        }
    }

    /**
     * Anular sí se bloquea entero, y con razón.
     *
     * Anular borra la compra: la mercancía tiene que salir completa. No hay
     * diferencia que mover.
     */
    public function test_anular_sigue_bloqueado_cuando_ya_se_vendio(): void
    {
        $producto = $this->producto();
        $compra = $this->compraContabilizada($producto, cantidad: 6);

        $this->vender($producto, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Devoluciones/');

        $this->motor->cancel($compra->fresh());
    }

    /** Un servicio no mueve inventario, así que nunca estorba. */
    public function test_una_compra_de_servicios_no_se_bloquea(): void
    {
        $servicio = $this->producto(inventariable: false);
        $compra = $this->compraContabilizada($servicio, cantidad: 6, contabilizar: false);
        $compra->update(['status' => PurchaseInvoice::STATUS_POSTED]);

        $this->assertNull($this->motor->porQueNoSePuedeDevolverElInventario($compra->fresh()));
    }

    // --------------------------------------------------------- auxiliares

    private function producto(bool $inventariable = true): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZSV'.random_int(10000, 99999),
            'name' => 'ZZSV Perfume de prueba',
            'type' => $inventariable ? 'good' : 'service',
            'unit_of_measure' => $inventariable ? 'unit' : 'service',
            'track_inventory' => $inventariable,
            'is_purchasable' => true,
            'is_sellable' => true,
            'default_sale_price' => 300000,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()
            ->whereKey($producto->id)->forceDelete();

        return $producto;
    }

    private function compraContabilizada(
        Product $producto,
        float $cantidad,
        ?float $segundaLinea = null,
        bool $contabilizar = true,
    ): PurchaseInvoice {
        $total = 270000 * ($cantidad + ($segundaLinea ?? 0));

        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->proveedor->id,
            'prefix' => 'ZZSV',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        $linea = 1;

        foreach (array_filter([$cantidad, $segundaLinea]) as $cant) {
            PurchaseInvoiceLine::withoutGlobalScopes()->create([
                'purchase_invoice_id' => $factura->id,
                'line_number' => $linea++,
                'product_id' => $producto->id,
                'description' => $producto->name,
                'quantity' => $cant,
                'unit_cost' => 270000,
                'subtotal' => 270000 * $cant,
                'total' => 270000 * $cant,
            ]);
        }

        $this->limpiar[] = function () use ($factura, $producto) {
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            $asientos = DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)
                ->where('reference', 'like', '%'.$factura->number.'%')->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id', $factura->id)->delete();
            DB::table('purchase_invoices')->where('id', $factura->id)->delete();
        };

        if ($contabilizar) {
            $this->motor->post($factura->fresh(['lines']));
        }

        return $factura->fresh();
    }

    /** Corrige la factura dejando la línea en una cantidad nueva. */
    private function corregirCantidad(PurchaseInvoice $compra, float $cantidad): void
    {
        $this->motor->prepararParaEditar($compra->fresh());
        PurchaseInvoiceLine::withoutGlobalScopes()
            ->where('purchase_invoice_id', $compra->id)->update(['quantity' => $cantidad]);
        $this->motor->post($compra->fresh(['lines']));
    }

    private function stock(Product $producto): float
    {
        return app(InventoryEngine::class)->currentStock($producto->id, $this->sede->id);
    }

    private function cuantosMovimientos(Product $producto): int
    {
        return (int) DB::table('inventory_movements')->where('product_id', $producto->id)->count();
    }

    /** Saca mercancía como lo haría una venta. */
    private function vender(Product $producto, float $cantidad): void
    {
        app(InventoryEngine::class)->addMovement($producto, $this->sede, [
            'type' => 'sale',
            'quantity' => $cantidad,
            'unit_cost' => 270000,
            'date' => now(),
            'description' => 'ZZSV venta de prueba',
        ]);
    }
}
