<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\PosSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * El ajuste "Permitir vender sin stock".
 *
 * Estaba en la configuración y no hacía nada: el POS lo leía para su
 * formulario pero llamaba a SaleInvoiceEngine::post() sin pasarlo, así que el
 * motor rechazaba la venta igual. El POS de restaurante sí lo pasaba, de modo
 * que la misma empresa se comportaba distinto según por dónde vendiera.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PosNegativeStockTest extends TestCase
{
    private Company $company;

    private Location $sede;

    private Product $producto;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->prepararCatalogo();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** Sin el ajuste, una venta que deja el inventario en rojo se rechaza. */
    public function test_sin_el_ajuste_la_venta_sin_stock_se_rechaza(): void
    {
        $this->ajustarPermitirNegativo(false);

        $factura = $this->facturaBorrador(cantidad: 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Stock insuficiente/');

        app(SaleInvoiceEngine::class)->post(
            $factura,
            allowNegativeStock: PosSettings::allowsNegativeStock(),
        );
    }

    /** Con el ajuste marcado, la venta sale y el faltante queda en el kardex. */
    public function test_con_el_ajuste_la_venta_sale_y_el_saldo_queda_negativo(): void
    {
        $this->ajustarPermitirNegativo(true);

        $factura = $this->facturaBorrador(cantidad: 4);

        $contabilizada = app(SaleInvoiceEngine::class)->post(
            $factura,
            allowNegativeStock: PosSettings::allowsNegativeStock(),
        );

        $this->assertSame(SaleInvoice::STATUS_POSTED, $contabilizada->status);

        $saldo = app(InventoryEngine::class)
            ->currentStock($this->producto->id, $this->sede->id);

        $this->assertEqualsWithDelta(-4, $saldo, 0.01,
            'La salida debe quedar registrada aunque deje el saldo en rojo.');
    }

    /** El accesor lee el ajuste real de la empresa, no un valor por defecto. */
    public function test_el_accesor_refleja_lo_que_la_empresa_tiene_configurado(): void
    {
        $this->ajustarPermitirNegativo(true);
        $this->assertTrue(PosSettings::allowsNegativeStock());

        $this->ajustarPermitirNegativo(false);
        $this->assertFalse(PosSettings::allowsNegativeStock());
    }

    /**
     * Que el motor lo respete no basta: hay que pasárselo. Ese era justo el
     * fallo —la opción se configuraba, el POS la leía para su formulario y
     * luego llamaba a post() sin ella—.
     */
    public function test_las_tres_vias_de_facturacion_pasan_el_ajuste(): void
    {
        $sinPasarlo = [];

        $vias = [
            'POS retail' => app_path('Filament/App/Pages/PosTerminal.php'),
            'Factura de venta' => app_path('Filament/App/Resources/SaleInvoiceResource/Pages/ViewSaleInvoice.php'),
            'POS restaurante' => app_path('../app/Services/Restaurant/RestaurantOrderEngine.php'),
        ];

        foreach ($vias as $nombre => $ruta) {
            if (! str_contains(file_get_contents($ruta), 'allowNegativeStock:')) {
                $sinPasarlo[] = $nombre;
            }
        }

        $this->assertSame([], $sinPasarlo,
            'Estas vías contabilizan sin decir si la empresa permite vender sin stock: '
            .implode(', ', $sinPasarlo));
    }

    private function ajustarPermitirNegativo(bool $permitir): void
    {
        $settings = $this->company->settings ?? [];
        $settings['pos']['allow_negative_stock'] = $permitir;
        $this->company->update(['settings' => $settings]);
        app(CurrentCompany::class)->set($this->company->fresh());
    }

    private function facturaBorrador(float $cantidad): SaleInvoice
    {
        $total = 100000 * $cantidad;

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZSTK',
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

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => $cantidad,
            'unit_price' => 100000,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            DB::table('inventory_movements')->where('reference_id', $factura->id)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            SaleInvoice::withoutGlobalScopes()->whereKey($factura->id)->forceDelete();
        };

        return $factura->fresh(['lines']);
    }

    private function prepararCatalogo(): void
    {
        $ajustesOriginales = $this->company->settings;
        $this->limpiar[] = fn () => $this->company->update(['settings' => $ajustesOriginales]);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->firstOrFail();

        // Producto propio del test, con inventario controlado y sin saldo:
        // así la venta siempre intenta dejarlo en negativo.
        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZSTK'.random_int(10000, 99999),
            'name' => 'ZZ Producto sin existencias',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => true,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'default_purchase_price' => 50000,
            'active' => true,
        ]);

        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE STOCK',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('inventory_movements')->where('product_id', $this->producto->id)->delete();
            DB::table('product_locations')->where('product_id', $this->producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }
}
