<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PurchaseInvoiceResource\Pages\EditPurchaseInvoice;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\EditSaleInvoice;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editar una factura no puede dejarla en $0.
 *
 * Es lo que pasaba, en ventas y en compras: la pantalla abría, las líneas se
 * guardaban bien, y el total de la factura quedaba en cero.
 *
 * **Por qué.** El repetidor de líneas usa `->relationship('lines')`, y
 * Filament saca esos campos del arreglo de datos antes de guardar para
 * persistirlos aparte. Las páginas sumaban los totales recorriendo
 * `$data['lines']`, que para entonces ya venía vacío: la suma daba cero y
 * escribía cero. Una factura de $500.000 quedaba guardada en $0 con sus
 * líneas intactas, y al contabilizarla generaba un asiento de cero.
 *
 * Nadie lo vio porque ninguna prueba editaba una factura por la pantalla y
 * miraba después el total. Esta sí.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class EditarFacturaTotalesTest extends TestCase
{
    private Company $empresa;

    private Location $sede;

    private ThirdParty $tercero;

    private Product $producto;

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
        $this->tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZET'.random_int(10000, 99999),
            'name' => 'ZZET Producto',
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_purchasable' => true,
            'is_sellable' => true,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => Product::withoutGlobalScopes()
            ->whereKey($this->producto->id)->forceDelete();
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

    // ------------------------------------------------------------ compras

    /** Guardar sin cambiar nada deja la compra como estaba. */
    public function test_guardar_una_compra_sin_cambios_conserva_el_total(): void
    {
        $compra = $this->compra(500000);

        Livewire::test(EditPurchaseInvoice::class, ['record' => $compra->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(500000, (float) $compra->fresh()->total, 0.01,
            'Guardar sin tocar nada no puede cambiar la factura.');
    }

    /** Y cambiar la cantidad mueve el total en consecuencia. */
    public function test_cambiar_la_cantidad_de_una_compra_recalcula_el_total(): void
    {
        $compra = $this->compra(500000);

        $pagina = Livewire::test(EditPurchaseInvoice::class, ['record' => $compra->getRouteKey()]);

        $lineas = $pagina->get('data.lines');
        $clave = array_key_first($lineas);
        $lineas[$clave]['quantity'] = 2;
        $lineas[$clave]['subtotal'] = 1000000;
        $lineas[$clave]['total'] = 1000000;

        $pagina->set('data.lines', $lineas)->call('save')->assertHasNoFormErrors();

        $fresca = $compra->fresh(['lines']);

        $this->assertEqualsWithDelta(2, (float) $fresca->lines->first()->quantity, 0.01);
        $this->assertEqualsWithDelta(1000000, (float) $fresca->total, 0.01,
            'El total tiene que seguir a las líneas, no quedarse en cero ni en el valor viejo.');
    }

    /** El neto a pagar también, que es contra lo que se cobra el saldo. */
    public function test_la_compra_conserva_su_neto_a_pagar(): void
    {
        $compra = $this->compra(300000);

        Livewire::test(EditPurchaseInvoice::class, ['record' => $compra->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(300000, (float) $compra->fresh()->net_payable, 0.01);
    }

    // ------------------------------------------------------------ ventas

    /** Lo mismo en ventas: era el mismo defecto, no uno parecido. */
    public function test_guardar_una_venta_sin_cambios_conserva_el_total(): void
    {
        $venta = $this->venta(400000);

        Livewire::test(EditSaleInvoice::class, ['record' => $venta->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresca = $venta->fresh();

        $this->assertEqualsWithDelta(400000, (float) $fresca->total, 0.01);
        $this->assertEqualsWithDelta(400000, (float) $fresca->net_payable, 0.01);
    }

    /** Y cambiar la cantidad recalcula. */
    public function test_cambiar_la_cantidad_de_una_venta_recalcula_el_total(): void
    {
        $venta = $this->venta(400000);

        $pagina = Livewire::test(EditSaleInvoice::class, ['record' => $venta->getRouteKey()]);

        $lineas = $pagina->get('data.lines');
        $clave = array_key_first($lineas);
        $lineas[$clave]['quantity'] = 3;
        $lineas[$clave]['subtotal'] = 1200000;
        $lineas[$clave]['total'] = 1200000;

        $pagina->set('data.lines', $lineas)->call('save')->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(1200000, (float) $venta->fresh()->total, 0.01);
    }

    // ----------------------------------------------------------- guardas

    /** Una compra contabilizada no se edita por aquí. */
    public function test_no_se_edita_una_compra_contabilizada(): void
    {
        $compra = $this->compra(200000);
        $compra->update(['status' => PurchaseInvoice::STATUS_POSTED]);

        // La guarda lanza una ValidationException con clave propia, no un
        // error de campo del formulario: por eso `assertHasErrors` y no
        // `assertHasFormErrors`, que antepone `data.`.
        Livewire::test(EditPurchaseInvoice::class, ['record' => $compra->getRouteKey()])
            ->call('save')
            ->assertHasErrors('status');

        $this->assertEqualsWithDelta(200000, (float) $compra->fresh()->total, 0.01);
    }

    // --------------------------------------------------------- auxiliares

    private function compra(float $total): PurchaseInvoice
    {
        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero->id,
            'prefix' => 'ZZET',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        PurchaseInvoiceLine::withoutGlobalScopes()->create([
            'purchase_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_cost' => $total,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id', $factura->id)->delete();
            DB::table('purchase_invoices')->where('id', $factura->id)->delete();
        };

        return $factura->fresh();
    }

    private function venta(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero->id,
            'prefix' => 'ZZET',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'invoice_kind' => 'electronic',
            'origin' => SaleInvoice::ORIGIN_FORM,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
        };

        return $factura->fresh();
    }
}
