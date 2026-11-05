<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Inventory\InventoryEngine;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Corregir una factura ya contabilizada.
 *
 * Hasta ahora no había forma. El botón de editar solo salía en borrador, y
 * anular exige que no haya pagos —cosa que una venta POS siempre tiene—. Lo
 * que se hacía en la práctica era «arreglarlo» en la siguiente venta, que es
 * como el inventario y la caja dejan de parecerse a la realidad.
 *
 * Lo que estas pruebas fijan son las tres cosas que hacen que esto sea seguro:
 *
 *  1. **Deshace todo**, no solo las líneas: inventario, asientos y pagos.
 *  2. **El cierre de caja refleja la corrección.** Por eso los pagos se borran
 *     en vez de conservarse: con el total cambiado, un pago viejo deja el
 *     arqueo mostrando una cifra que ya no corresponde a nada.
 *  3. **No se puede cuando tocaría un documento que ya no es nuestro**: una
 *     factura aceptada por la DIAN, o un arqueo que alguien ya firmó.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class EdicionDeFacturaTest extends TestCase
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

    /* ------------------------------------------------------------------ */
    /*  Deshacer */
    /* ------------------------------------------------------------------ */

    /** Vuelve a borrador, que es lo que permite editarla. */
    public function test_la_factura_vuelve_a_borrador(): void
    {
        $factura = $this->facturaPosPagada(200000);

        $this->assertTrue($factura->isPosted());

        $editable = app(SaleInvoiceEngine::class)->prepararParaEditar($factura);

        $this->assertTrue($editable->isDraft());
        $this->assertEqualsWithDelta(0, (float) $editable->paid_amount, 0.01);
        $this->assertNull($editable->journal_entry_id);
    }

    /** El inventario vuelve: lo que no se vendió no puede quedar descargado. */
    public function test_el_inventario_vuelve(): void
    {
        $this->entrada(10);

        $antes = $this->stock();
        $factura = $this->facturaPosPagada(200000);

        $this->assertEqualsWithDelta($antes - 2, $this->stock(), 0.001, 'La venta descarga 2.');

        app(SaleInvoiceEngine::class)->prepararParaEditar($factura);

        $this->assertEqualsWithDelta($antes, $this->stock(), 0.001,
            'Al corregir, lo que no se vendió tiene que volver al estante.');
    }

    /** Los pagos se borran reversando su asiento. */
    public function test_los_pagos_se_borran(): void
    {
        $factura = $this->facturaPosPagada(200000);

        $this->assertSame(1, $factura->payments()->count());

        app(SaleInvoiceEngine::class)->prepararParaEditar($factura);

        $this->assertSame(0, $factura->fresh()->payments()->count(),
            'Un pago viejo con el total cambiado deja el arqueo mintiendo.');
    }

    /**
     * Y el cierre de caja lo refleja.
     *
     * Es el punto entero del ejercicio: corregir una factura sin que el
     * arqueo se entere sería peor que no poder corregirla.
     */
    public function test_el_cierre_de_caja_refleja_la_correccion(): void
    {
        $turno = $this->turnoAbierto();
        $resumen = app(CashSessionSummary::class);

        $base = (float) $resumen->compute($turno)['expected_cash'];

        $factura = $this->facturaPosPagada(200000, $turno);

        $conVenta = (float) $resumen->compute($turno->fresh())['expected_cash'];
        $this->assertEqualsWithDelta($base + 200000, $conVenta, 0.01);

        app(SaleInvoiceEngine::class)->prepararParaEditar($factura);

        $despues = (float) $resumen->compute($turno->fresh())['expected_cash'];

        $this->assertEqualsWithDelta($base, $despues, 0.01,
            'Al devolver la factura a borrador, su cobro sale del arqueo.');
    }

    /** Deja asiento de reversa: no borra el original. */
    public function test_queda_rastro_de_la_correccion(): void
    {
        $factura = $this->facturaPosPagada(200000);

        app(SaleInvoiceEngine::class)->prepararParaEditar($factura);

        $reversas = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->empresa->id)
            ->where('description', 'like', '%Edición de venta%')
            ->count();

        $this->assertGreaterThan(0, $reversas,
            'Corregir no puede borrar la historia: tiene que quedar el asiento contrario.');
    }

    /** Corregida y vuelta a contabilizar, los totales son los nuevos. */
    public function test_se_corrige_y_se_vuelve_a_contabilizar(): void
    {
        $this->entrada(10);
        $factura = $this->facturaPosPagada(200000);

        app(SaleInvoiceEngine::class)->prepararParaEditar($factura);

        // La corrección: una unidad en vez de dos.
        $factura->lines()->first()->update([
            'quantity' => 1,
            'subtotal' => 100000,
            'total' => 100000,
        ]);

        $corregida = app(SaleInvoiceEngine::class)
            ->post($factura->fresh(['lines']), allowNegativeStock: true);

        $this->assertTrue($corregida->isPosted());
        $this->assertEqualsWithDelta(100000, (float) $corregida->total, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /*  Cuándo NO se puede */
    /* ------------------------------------------------------------------ */

    /** Una factura aceptada por la DIAN no se toca. */
    public function test_una_factura_aceptada_por_la_dian_no_se_edita(): void
    {
        $factura = $this->facturaPosPagada(100000);
        $factura->update([
            'invoice_kind' => 'electronic',
            'dian_status' => SaleInvoice::DIAN_ACCEPTED,
            'cufe' => 'ZZCUFE'.random_int(100000, 999999),
        ]);

        $motivo = app(SaleInvoiceEngine::class)->porQueNoSePuedeEditar($factura->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('nota crédito', $motivo);

        $this->expectException(\RuntimeException::class);
        app(SaleInvoiceEngine::class)->prepararParaEditar($factura->fresh());
    }

    /** Una enviada y sin respuesta, tampoco. */
    public function test_una_enviada_a_la_dian_no_se_edita(): void
    {
        $factura = $this->facturaPosPagada(100000);
        $factura->update(['invoice_kind' => 'electronic', 'dian_status' => SaleInvoice::DIAN_SENT]);

        $motivo = app(SaleInvoiceEngine::class)->porQueNoSePuedeEditar($factura->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('esperando respuesta', $motivo);
    }

    /** Una POS sí, aunque tenga pagos: es el caso que motivó todo esto. */
    public function test_una_pos_pagada_si_se_edita(): void
    {
        $factura = $this->facturaPosPagada(100000);

        $this->assertNull(app(SaleInvoiceEngine::class)->porQueNoSePuedeEditar($factura));
    }

    /**
     * Pero no si su turno ya cerró.
     *
     * Ese arqueo lo firmó alguien. Cambiarlo por detrás lo convierte en un
     * documento que no significa nada.
     */
    public function test_no_se_edita_si_el_turno_ya_cerro(): void
    {
        $turno = $this->turnoAbierto();
        $factura = $this->facturaPosPagada(100000, $turno);

        DB::table('cash_register_sessions')->where('id', $turno->id)
            ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

        $motivo = app(SaleInvoiceEngine::class)->porQueNoSePuedeEditar($factura->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('turno de caja ya cerrado', $motivo);
    }

    /** Una que ya está en borrador no necesita nada. */
    public function test_un_borrador_ya_es_editable(): void
    {
        $factura = $this->facturaBorrador(50000);

        $this->assertNull(app(SaleInvoiceEngine::class)->porQueNoSePuedeEditar($factura));
    }

    // --------------------------------------------------------- auxiliares

    private function stock(): float
    {
        return app(InventoryEngine::class)->currentStock($this->producto->id, $this->sede->id);
    }

    private function entrada(float $cantidad): void
    {
        app(InventoryEngine::class)->addMovement($this->producto, $this->sede, [
            'type' => 'adjustment_in',
            'quantity' => $cantidad,
            'unit_cost' => 50000,
            'date' => now()->toDateString(),
            'description' => 'ZZEDI carga de prueba',
        ]);
    }

    private function facturaBorrador(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZEDI',
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
            'quantity' => $total / 100000,
            'unit_price' => 100000,
            'cost_at_sale' => 50000,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->empresa->id)
                ->where(fn ($q) => $q->where('reference', 'like', '%ZZEDI%')
                    ->orWhere('description', 'like', '%ZZEDI%'))
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

    private function facturaPosPagada(float $total, ?CashRegisterSession $turno = null): SaleInvoice
    {
        $turno ??= $this->turnoAbierto();

        $factura = $this->facturaBorrador($total);
        $factura->update(['cash_register_session_id' => $turno->id]);

        $posteada = app(SaleInvoiceEngine::class)
            ->post($factura->fresh(['lines']), allowNegativeStock: true);

        app(SaleInvoiceEngine::class)->addPayment($posteada, [
            'amount' => $total,
            'payment_method' => 'cash',
            'date' => now()->toDateString(),
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'cash_register_session_id' => $turno->id,
        ]);

        return $posteada->fresh();
    }

    private function turnoAbierto(): CashRegisterSession
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZEDI'.random_int(100000, 999999),
            'name' => 'ZZEDI Cliente edicion',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZEDI'.random_int(10000, 99999),
            'name' => 'ZZEDI Producto edicion',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => true,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'default_purchase_price' => 50000,
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
