<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pagar la compra en el mismo momento de contabilizarla.
 *
 * Una compra de contado obligaba a tres pasos: contabilizar, abrir la pestaña
 * de pagos y registrar el pago. Y el usuario ya está en el momento en que sabe
 * si la pagó o no: acaba de recibir la mercancía con la factura en la mano.
 *
 * **El interruptor nace apagado a propósito.** La compra a crédito es igual
 * de corriente, y a quien no paga hoy no se le pone un formulario de pago
 * delante.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CompraPagoInmediatoTest extends TestCase
{
    private Company $empresa;

    private Location $sede;

    private ThirdParty $proveedor;

    private Product $producto;

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

        // Un pago a proveedor exige turno de caja abierto: esa plata sale del
        // cajón y tiene que quedar en el arqueo de alguien. La prueba fija el
        // estado del que depende en vez de heredarlo.
        $this->turnoAbierto();
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

    /** Contabiliza y paga en un solo paso. */
    public function test_contabiliza_y_paga_de_una_vez(): void
    {
        $factura = $this->borrador(500000);

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('post', data: [
                'pagar_ahora' => true,
                'amount' => 500000,
                'date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);

        $fresca = $factura->fresh();

        $this->assertTrue($fresca->isPosted());
        $this->assertEqualsWithDelta(500000, (float) $fresca->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fresca->balance, 0.01);
    }

    /**
     * Apagado, contabiliza y no paga.
     *
     * La compra a crédito es igual de corriente: el pago no puede colarse por
     * defecto.
     */
    public function test_apagado_contabiliza_sin_pagar(): void
    {
        $factura = $this->borrador(300000);

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('post', data: ['pagar_ahora' => false]);

        $fresca = $factura->fresh();

        $this->assertTrue($fresca->isPosted());
        $this->assertEqualsWithDelta(0, (float) $fresca->paid_amount, 0.01);
    }

    /** Admite un abono parcial, no solo el total. */
    public function test_admite_un_abono_parcial(): void
    {
        $factura = $this->borrador(400000);

        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('post', data: [
                'pagar_ahora' => true,
                'amount' => 150000,
                'date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);

        $fresca = $factura->fresh();

        $this->assertEqualsWithDelta(150000, (float) $fresca->paid_amount, 0.01);
        $this->assertEqualsWithDelta(250000, (float) $fresca->balance, 0.01);
    }

    /**
     * Si el pago falla, la factura QUEDA contabilizada.
     *
     * Y hay que decirlo: si el usuario cree que no pasó nada, vuelve a
     * intentar todo y termina con la compra duplicada.
     */
    public function test_si_el_pago_falla_la_factura_queda_contabilizada(): void
    {
        $factura = $this->borrador(200000);

        // Más de lo que vale: el motor rechaza el pago, no la contabilización.
        Livewire::test(ViewPurchaseInvoice::class, ['record' => $factura->getRouteKey()])
            ->callAction('post', data: [
                'pagar_ahora' => true,
                'amount' => 900000,
                'date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);

        $fresca = $factura->fresh();

        $this->assertTrue($fresca->isPosted(), 'La contabilización ya había ocurrido.');
        $this->assertEqualsWithDelta(0, (float) $fresca->paid_amount, 0.01);
    }

    private function turnoAbierto(): void
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', 'open')
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => 'closed', 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => 'open', 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => 'open',
            'opened_at' => now(),
            'opening_amount' => 2000000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }

    // --------------------------------------------------------- auxiliares

    private function borrador(float $total): PurchaseInvoice
    {
        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->proveedor->id,
            'prefix' => 'ZZCOM',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => 'draft',
            'payment_status' => 'pendiente',
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
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->empresa->id)
                ->where('reference', 'like', '%ZZCOM%')
                ->pluck('id');
            DB::table('payments')->where('paymentable_id', $factura->id)
                ->where('paymentable_type', PurchaseInvoice::class)->delete();
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)
                ->where('reference_type', PurchaseInvoice::class)->delete();
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id', $factura->id)->delete();
            DB::table('purchase_invoices')->where('id', $factura->id)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };

        return $factura->fresh(['lines']);
    }

    private function prepararCatalogo(): void
    {
        $this->proveedor = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZCOM'.random_int(100000, 999999),
            'name' => 'ZZCOM Proveedor pago',
            'is_supplier' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZCOM'.random_int(10000, 99999),
            'name' => 'ZZCOM Producto compra',
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_purchasable' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->proveedor->id)->forceDelete();
        };
    }
}
