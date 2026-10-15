<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Vender un combo descarga sus componentes.
 *
 * El tipo «kit / combo» existía desde el principio y la ficha del producto
 * prometía que «al venderse desglosa componentes», pero no había dónde
 * definirlos: ni tabla, ni relación, ni pantalla. Era una etiqueta, y un combo
 * se vendía sin descargar nada y con margen del 100%.
 *
 * Un combo no lleva inventario propio —se arma al venderlo—, así que lo que
 * sale del almacén son sus piezas y el costo de la venta es lo que costaron
 * esas piezas.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ProductKitTest extends TestCase
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

    /** Vender un combo saca del almacén sus piezas, no el combo. */
    public function test_vender_un_combo_descarga_sus_componentes(): void
    {
        $pan = $this->productoConStock('ZZK-PAN', 100);
        $carne = $this->productoConStock('ZZK-CARNE', 100);

        $combo = $this->combo('ZZK-COMBO', [[$pan, 2], [$carne, 1]]);

        $this->venderCombo($combo, 3);

        // 3 combos × 2 panes = 6; 3 combos × 1 carne = 3.
        $this->assertSame(94.0, $this->stockDe($pan));
        $this->assertSame(97.0, $this->stockDe($carne));
    }

    /** El costo de la venta es el de las piezas. */
    public function test_el_costo_sale_de_los_componentes(): void
    {
        $pan = $this->productoConStock('ZZK-PAN2', 50, costo: 1000);
        $carne = $this->productoConStock('ZZK-CARNE2', 50, costo: 5000);

        $combo = $this->combo('ZZK-COMBO2', [[$pan, 2], [$carne, 1]]);

        $factura = $this->venderCombo($combo, 2);

        // Por combo: 2 panes a 1.000 + 1 carne a 5.000 = 7.000.
        $linea = $factura->fresh(['lines'])->lines->first();

        $this->assertEqualsWithDelta(7000, (float) $linea->cost_at_sale, 1,
            'Sin esto el combo tendría margen del 100%: nunca se compró, se armó.');
    }

    /**
     * Un combo sin receta no se puede vender.
     *
     * Dejarlo pasar lo convertiría en una venta sin costo y sin salida de
     * inventario: margen del 100% y almacén intacto, que es el error que nadie
     * revisa hasta que cuadra el inventario a fin de mes.
     */
    public function test_un_combo_sin_componentes_no_se_vende(): void
    {
        $combo = $this->combo('ZZK-VACIO', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene componentes/');

        $this->venderCombo($combo, 1);
    }

    /** Anular la venta devuelve las piezas al almacén. */
    public function test_anular_devuelve_los_componentes(): void
    {
        $pan = $this->productoConStock('ZZK-PAN3', 40);
        $combo = $this->combo('ZZK-COMBO3', [[$pan, 2]]);

        $factura = $this->venderCombo($combo, 5);

        $this->assertSame(30.0, $this->stockDe($pan));

        app(SaleInvoiceEngine::class)->cancel($factura->fresh(['lines.product', 'customer', 'location']));

        $this->assertSame(40.0, $this->stockDe($pan),
            'Sin esto, anular una venta de combos dejaba el inventario descargado para siempre.');
    }

    /** Un componente sin inventario —un servicio— no estorba. */
    public function test_un_componente_sin_inventario_no_estorba(): void
    {
        $pan = $this->productoConStock('ZZK-PAN4', 20);
        $servicio = $this->producto('ZZK-SERV', trackInventory: false);

        $combo = $this->combo('ZZK-COMBO4', [[$pan, 1], [$servicio, 1]]);

        $this->venderCombo($combo, 2);

        $this->assertSame(18.0, $this->stockDe($pan));
    }

    // ------------------------------------------- cuántos combos se pueden armar

    /** Lo limita la pieza que menos alcance. */
    public function test_los_combos_disponibles_los_limita_la_pieza_mas_escasa(): void
    {
        $pan = $this->productoConStock('ZZK-PAN5', 10);
        $carne = $this->productoConStock('ZZK-CARNE5', 3);

        $combo = $this->combo('ZZK-COMBO5', [[$pan, 1], [$carne, 1]]);

        $this->assertSame(3.0, $combo->fresh()->combosDisponiblesEn($this->sede->id),
            'Con pan para 10 y carne para 3 se arman 3, no 10.');
    }

    /** Y cuenta la cantidad de la receta, no solo las existencias. */
    public function test_la_receta_cuenta_para_lo_disponible(): void
    {
        $pan = $this->productoConStock('ZZK-PAN6', 10);

        $combo = $this->combo('ZZK-COMBO6', [[$pan, 3]]);

        $this->assertSame(3.0, $combo->fresh()->combosDisponiblesEn($this->sede->id),
            'Con 10 panes y 3 por combo salen 3 combos y sobra uno.');
    }

    /**
     * Sin receta devuelve null, no cero.
     *
     * No es lo mismo: un cero inventado haría parecer agotado algo que no lo
     * está, y el POS lo escondería.
     */
    public function test_sin_receta_no_inventa_un_cero(): void
    {
        $combo = $this->combo('ZZK-COMBO7', []);

        $this->assertNull($combo->fresh()->combosDisponiblesEn($this->sede->id));
    }

    // --------------------------------------------------------- auxiliares

    private function producto(string $codigo, bool $trackInventory = true, float $costo = 1000): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ '.$codigo,
            'type' => $trackInventory ? 'good' : 'service',
            'active' => true,
            'is_sellable' => true,
            'track_inventory' => $trackInventory,
            'default_sale_price' => 20000,
            'default_purchase_price' => $costo,
        ]);

        $this->limpiar[] = function () use ($producto) {
            DB::table('product_components')
                ->where('kit_product_id', $producto->id)
                ->orWhere('component_product_id', $producto->id)->delete();
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            DB::table('product_locations')->where('product_id', $producto->id)->delete();
            DB::table('products')->where('id', $producto->id)->delete();
        };

        return $producto;
    }

    private function productoConStock(string $codigo, float $cantidad, float $costo = 1000): Product
    {
        $producto = $this->producto($codigo, costo: $costo);

        app(InventoryEngine::class)->addMovement($producto, $this->sede, [
            'type' => 'opening',
            'quantity' => $cantidad,
            'unit_cost' => $costo,
            'date' => now()->subDay(),
            'description' => 'ZZ saldo de prueba',
        ]);

        return $producto;
    }

    /** @param  list<array{0: Product, 1: float}>  $piezas */
    private function combo(string $codigo, array $piezas): Product
    {
        $combo = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ Combo '.$codigo,
            'type' => 'kit',
            'active' => true,
            'is_sellable' => true,
            'track_inventory' => false,
            'default_sale_price' => 30000,
        ]);

        $this->limpiar[] = function () use ($combo) {
            DB::table('product_components')->where('kit_product_id', $combo->id)->delete();
            DB::table('products')->where('id', $combo->id)->delete();
        };

        foreach ($piezas as [$producto, $cantidad]) {
            ProductComponent::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'kit_product_id' => $combo->id,
                'component_product_id' => $producto->id,
                'quantity' => $cantidad,
            ]);
        }

        return $combo;
    }

    private function venderCombo(Product $combo, float $cantidad): SaleInvoice
    {
        $tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $tercero->id,
            'prefix' => 'ZZKIT',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_DRAFT,
            'invoice_kind' => 'pos',
            'subtotal' => 30000 * $cantidad,
            'tax_total' => 0,
            'total' => 30000 * $cantidad,
            'net_payable' => 30000 * $cantidad,
        ]);

        SaleInvoiceLine::create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $combo->id,
            'description' => $combo->name,
            'quantity' => $cantidad,
            'unit_price' => 30000,
            'subtotal' => 30000 * $cantidad,
            'total' => 30000 * $cantidad,
        ]);

        $this->limpiar[] = function () use ($factura) {
            DB::table('inventory_movements')
                ->where('reference_type', SaleInvoice::class)
                ->where('reference_id', $factura->id)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
        };

        return app(SaleInvoiceEngine::class)->post($factura->fresh(['lines.product']));
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
