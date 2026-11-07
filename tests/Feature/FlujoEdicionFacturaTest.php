<?php

namespace Tests\Feature;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\EditSaleInvoice;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\ViewSaleInvoice;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El camino de corregir una factura, de punta a punta.
 *
 * Eran cuatro pasos: guardar, volver a la ficha, buscar «Contabilizar»,
 * confirmar. Y entre el primero y el segundo **no pasaba nada visible**, así
 * que la factura se quedaba en borrador sin que nadie lo notara.
 *
 * Eso no es solo lentitud: una factura en borrador **no descarga inventario
 * ni genera asiento**. El error no se ve hasta que las cifras dejan de
 * cuadrar, semanas después, y para entonces nadie recuerda cuál factura fue.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class FlujoEdicionFacturaTest extends TestCase
{
    private Company $empresa;

    private Location $sede;

    private Product $producto;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->prepararCatalogo();
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

    /**
     * Guardar y contabilizar en un solo paso, y al listado.
     *
     * Es el camino que convierte los cuatro pasos en uno.
     */
    public function test_guardar_y_contabilizar_deja_la_factura_lista(): void
    {
        $factura = $this->borrador(200000);

        Livewire::test(EditSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('guardarYContabilizar')
            ->assertRedirect(SaleInvoiceResource::getUrl('index'));

        $fresca = $factura->fresh();

        $this->assertTrue($fresca->isPosted(),
            'Una factura que se queda en borrador no descarga inventario ni genera asiento.');
        $this->assertNotNull($fresca->journal_entry_id);
    }

    /** Contabilizar desde la ficha también devuelve al listado. */
    public function test_contabilizar_desde_la_ficha_vuelve_al_listado(): void
    {
        $factura = $this->borrador(150000);

        Livewire::test(ViewSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('post')
            ->assertRedirect(SaleInvoiceResource::getUrl('index'));

        $this->assertTrue($factura->fresh()->isPosted());
    }

    /**
     * Al guardar sin contabilizar, lleva a la ficha.
     *
     * Es donde está el botón de contabilizar. Antes el guardado dejaba al
     * usuario en el mismo formulario, sin señal de que faltaba un paso.
     */
    public function test_guardar_lleva_a_la_ficha(): void
    {
        $factura = $this->borrador(100000);

        Livewire::test(EditSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->call('save')
            ->assertRedirect(SaleInvoiceResource::getUrl('view', ['record' => $factura->getRouteKey()]));

        $this->assertTrue($factura->fresh()->isDraft(), 'Guardar no contabiliza por su cuenta.');
    }

    /** Sobre una factura ya contabilizada, el botón no existe. */
    public function test_no_se_ofrece_sobre_una_factura_contabilizada(): void
    {
        $factura = $this->borrador(100000);

        Livewire::test(EditSaleInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('guardarYContabilizar');

        Livewire::test(ViewSaleInvoice::class, ['record' => $factura->fresh()->getRouteKey()])
            ->assertActionHidden('post');
    }

    // --------------------------------------------------------- auxiliares

    private function borrador(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZFLU',
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
            'quantity' => 1,
            'unit_price' => $total,
            'cost_at_sale' => 0,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->empresa->id)
                ->where('reference', 'like', '%ZZFLU%')
                ->pluck('id');
            DB::table('payments')->where('paymentable_id', $factura->id)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)
                ->where('reference_type', SaleInvoice::class)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };

        return $factura->fresh(['lines']);
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZFLU'.random_int(100000, 999999),
            'name' => 'ZZFLU Cliente flujo',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZFLU'.random_int(10000, 99999),
            'name' => 'ZZFLU Producto flujo',
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }
}
