<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\CreditDebitNoteLine;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Sales\CreditDebitNoteEngine;
use App\Services\Sales\CustomerCreditGuard;
use App\Services\Sales\CustomerStatement;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Devoluciones de venta: que rebajen la cartera, y que existan para el POS.
 *
 * Dos huecos distintos, y el primero era el peligroso.
 *
 * **La nota crédito no rebajaba lo que el cliente debía.** El asiento
 * acreditaba la CxC, así que los libros quedaban bien, pero el saldo de la
 * factura no lo tocaba nadie. El cliente devolvía la mercancía y seguía
 * debiéndola en el estado de cuenta, en la cartera por edades y en el cupo de
 * crédito. Lo que se cobra no son los libros: es la cartera.
 *
 * **Y una factura POS no tenía cómo devolverse.** La nota crédito electrónica
 * es para la factura electrónica —lleva resolución y se transmite—; el tiquete
 * POS no se transmite, así que su devolución es un documento interno. Y como
 * la venta POS ya está pagada, ahí no hay cartera que rebajar: hay plata que
 * sale del cajón, y el arqueo tiene que saberlo.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class SaleReturnTest extends TestCase
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

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

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
    /*  Lo que de verdad importa: la cartera */
    /* ------------------------------------------------------------------ */

    /**
     * La devolución rebaja el saldo de la factura.
     *
     * Era el hueco grave: sin esto se le sigue cobrando al cliente algo que
     * ya devolvió, y el cobrador sale con un documento que dice una cifra que
     * los libros contradicen.
     */
    public function test_la_nota_credito_rebaja_el_saldo_de_la_factura(): void
    {
        $factura = $this->facturaACredito(200000);

        $this->assertEqualsWithDelta(200000, (float) $factura->balance, 0.01);

        $this->contabilizar($this->notaSobre($factura, 80000));

        $fresca = $factura->fresh();

        $this->assertEqualsWithDelta(80000, (float) $fresca->credited_amount, 0.01);
        $this->assertEqualsWithDelta(120000, (float) $fresca->balance, 0.01,
            'El cliente ya no debe lo que devolvió.');
    }

    /** Y por tanto desaparece de la cartera por edades y del cupo. */
    public function test_una_devolucion_total_saca_la_factura_de_la_cartera(): void
    {
        $factura = $this->facturaACredito(200000);

        $this->contabilizar($this->notaSobre($factura, 200000));

        $this->assertEqualsWithDelta(0, (float) $factura->fresh()->balance, 0.01);

        $deuda = app(CustomerCreditGuard::class)->currentDebt($this->cliente);

        $this->assertEqualsWithDelta(0, $deuda, 0.01,
            'Una factura devuelta completa no puede seguir consumiendo cupo.');
    }

    /** La devolución aparece en el estado de cuenta del cliente. */
    public function test_la_devolucion_sale_en_el_estado_de_cuenta(): void
    {
        $factura = $this->facturaACredito(200000);
        $nota = $this->contabilizar($this->notaSobre($factura, 80000));

        $estado = app(CustomerStatement::class)->build($this->cliente);

        $movimiento = $estado['movements']->firstWhere('type', CustomerStatement::NOTE);

        $this->assertNotNull($movimiento, 'La devolución no aparece en el estado de cuenta.');
        $this->assertEqualsWithDelta(80000, (float) $movimiento['credit'], 0.01);
        $this->assertSame($nota->fullNumber(), $movimiento['reference']);
    }

    /**
     * No se puede acreditar más de lo que la factura debe.
     *
     * Sin esto se podía devolver tres veces la misma venta: cada nota pasaba
     * sola porque nadie miraba las anteriores. Y ahora que la nota rebaja el
     * saldo, acreditar de más dejaría la factura en negativo, que la cartera
     * leería como un saldo a favor que nadie le dio al cliente.
     */
    public function test_no_se_puede_devolver_mas_de_lo_facturado(): void
    {
        $factura = $this->facturaACredito(200000);

        $this->contabilizar($this->notaSobre($factura, 150000));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/solo le quedan/i');

        $this->contabilizar($this->notaSobre($factura, 100000));
    }

    /* ------------------------------------------------------------------ */
    /*  Inventario */
    /* ------------------------------------------------------------------ */

    /** Con devolución física, el producto vuelve al stock. */
    public function test_la_mercancia_vuelve_al_inventario(): void
    {
        $factura = $this->facturaACredito(200000);

        $nota = $this->notaSobre($factura, 200000, fisica: true);
        $this->contabilizar($nota);

        $movimiento = DB::table('inventory_movements')
            ->where('reference_type', CreditDebitNote::class)
            ->where('reference_id', $nota->id)
            ->first();

        $this->assertNotNull($movimiento, 'No reingresó el producto.');
        $this->assertSame('return_from_customer', $movimiento->type);
        $this->assertEqualsWithDelta(2, (float) $movimiento->quantity, 0.01);
    }

    /** Sin devolución física —una rebaja— el inventario no se toca. */
    public function test_una_rebaja_no_mueve_inventario(): void
    {
        $factura = $this->facturaACredito(200000);

        $nota = $this->notaSobre($factura, 50000, fisica: false);
        $this->contabilizar($nota);

        $this->assertSame(0, DB::table('inventory_movements')
            ->where('reference_type', CreditDebitNote::class)
            ->where('reference_id', $nota->id)
            ->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Devolución POS: plata que sale del cajón */
    /* ------------------------------------------------------------------ */

    /** Una devolución POS no viaja a la DIAN. */
    public function test_la_devolucion_pos_no_va_a_la_dian(): void
    {
        $factura = $this->facturaACredito(100000, kind: 'pos');

        $nota = $this->notaSobre($factura, 100000);
        $nota->update(['kind' => CreditDebitNote::KIND_POS]);

        $posteada = $this->contabilizar($nota->fresh());

        $this->assertTrue($posteada->esPos());
        $this->assertFalse($posteada->viajaALaDian());
        $this->assertNull($posteada->dian_status,
            'Una devolución POS en la cola de la DIAN se intentaría enviar para siempre.');
        $this->assertNull($posteada->dian_resolution_id,
            'No puede quemar un consecutivo de la resolución de notas.');
    }

    /**
     * Devolver efectivo baja el esperado en caja.
     *
     * Si el arqueo no lo descuenta, al cerrar aparece un faltante por el valor
     * de lo devuelto y el cajero responde por una plata que entregó con un
     * documento de respaldo delante.
     */
    public function test_la_devolucion_en_efectivo_baja_el_arqueo(): void
    {
        $turno = $this->turnoAbierto(base: 300000);
        $factura = $this->facturaACredito(100000, kind: 'pos');

        $antes = (float) app(CashSessionSummary::class)->compute($turno)['expected_cash'];

        $nota = $this->notaSobre($factura, 100000);
        $nota->update([
            'kind' => CreditDebitNote::KIND_POS,
            'refund_method' => 'cash',
            'refund_amount' => 100000,
            'cash_register_session_id' => $turno->id,
        ]);

        $this->contabilizar($nota->fresh());

        $despues = (float) app(CashSessionSummary::class)->compute($turno->fresh())['expected_cash'];

        $this->assertEqualsWithDelta($antes - 100000, $despues, 0.01,
            'La plata devuelta tiene que salir del esperado en caja.');
    }

    /** No se devuelve efectivo que el cajón no tiene. */
    public function test_no_se_devuelve_mas_efectivo_del_que_hay(): void
    {
        $turno = $this->turnoAbierto(base: 20000);
        $factura = $this->facturaACredito(100000, kind: 'pos');

        $nota = $this->notaSobre($factura, 100000);
        $nota->update([
            'kind' => CreditDebitNote::KIND_POS,
            'refund_method' => 'cash',
            'refund_amount' => 100000,
            'cash_register_session_id' => $turno->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/En la caja hay/i');

        $this->contabilizar($nota->fresh());
    }

    /** Devolver efectivo exige turno abierto: esa plata sale del cajón de alguien. */
    public function test_devolver_efectivo_exige_turno_abierto(): void
    {
        $factura = $this->facturaACredito(100000, kind: 'pos');

        $nota = $this->notaSobre($factura, 100000);
        $nota->update([
            'kind' => CreditDebitNote::KIND_POS,
            'refund_method' => 'cash',
            'refund_amount' => 100000,
            'cash_register_session_id' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/turno de caja abierto/i');

        $this->contabilizar($nota->fresh());
    }

    /**
     * Lo devuelto en efectivo NO rebaja además el saldo.
     *
     * Contaría dos veces: la plata ya salió del cajón. Una venta de contado no
     * tiene cartera que bajar.
     */
    public function test_lo_devuelto_en_efectivo_no_rebaja_tambien_la_cartera(): void
    {
        $turno = $this->turnoAbierto(base: 300000);
        $factura = $this->facturaACredito(100000, kind: 'pos');

        $nota = $this->notaSobre($factura, 100000);
        $nota->update([
            'kind' => CreditDebitNote::KIND_POS,
            'refund_method' => 'cash',
            'refund_amount' => 100000,
            'cash_register_session_id' => $turno->id,
        ]);

        $this->contabilizar($nota->fresh());

        $this->assertEqualsWithDelta(0, (float) $factura->fresh()->credited_amount, 0.01,
            'La devolución en efectivo no puede además rebajar el saldo.');
    }

    /** El asiento de una devolución en efectivo acredita la caja, no la CxC. */
    public function test_el_asiento_de_la_devolucion_en_efectivo_acredita_la_caja(): void
    {
        $turno = $this->turnoAbierto(base: 300000);
        $factura = $this->facturaACredito(100000, kind: 'pos');

        $nota = $this->notaSobre($factura, 100000);
        $nota->update([
            'kind' => CreditDebitNote::KIND_POS,
            'refund_method' => 'cash',
            'refund_amount' => 100000,
            'cash_register_session_id' => $turno->id,
        ]);

        $posteada = $this->contabilizar($nota->fresh());

        $cuentaCaja = PaymentAccountResolver::forMethod('cash');

        $credito = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $posteada->journal_entry_id)
            ->where('account_id', $cuentaCaja)
            ->value('credit');

        $this->assertEqualsWithDelta(100000, (float) $credito, 0.01,
            'La plata salió de la caja: la caja se acredita.');
    }

    // --------------------------------------------------------- auxiliares

    private function contabilizar(CreditDebitNote $nota): CreditDebitNote
    {
        return app(CreditDebitNoteEngine::class)->post($nota);
    }

    private function notaSobre(SaleInvoice $factura, float $total, bool $fisica = false): CreditDebitNote
    {
        $cantidad = $total / 100000;

        $nota = CreditDebitNote::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'sale_invoice_id' => $factura->id,
            'type' => CreditDebitNote::TYPE_CREDIT,
            'prefix' => 'ZZDEV',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'reason_code' => 1,
            'reason_description' => 'Prueba de devolución',
            'affects_inventory' => $fisica,
            'refund_method' => CreditDebitNote::REFUND_BALANCE,
            'currency' => 'COP',
            'status' => CreditDebitNote::STATUS_DRAFT,
            'subtotal' => $total,
            'total' => $total,
        ]);

        CreditDebitNoteLine::withoutGlobalScopes()->create([
            'credit_debit_note_id' => $nota->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => $cantidad,
            'unit_price' => 100000,
            'cost_at_return' => 50000,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($nota) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company->id)
                ->where('reference', 'like', '%ZZDEV%')
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('inventory_movements')
                ->where('reference_type', CreditDebitNote::class)
                ->where('reference_id', $nota->id)->delete();
            DB::table('credit_debit_note_lines')->where('credit_debit_note_id', $nota->id)->delete();
            DB::table('credit_debit_notes')->where('id', $nota->id)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };

        return $nota->fresh(['lines']);
    }

    private function facturaACredito(float $total, string $kind = 'pos'): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZDEVF',
            'number' => random_int(100000, 999999),
            'invoice_kind' => $kind,
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
                ->where('company_id', $this->company->id)
                ->where('reference', 'like', '%ZZDEVF%')
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('inventory_movements')
                ->where('reference_type', SaleInvoice::class)
                ->where('reference_id', $factura->id)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };

        return app(SaleInvoiceEngine::class)->post($factura->fresh(['lines']), allowNegativeStock: true);
    }

    private function turnoAbierto(float $base): CashRegisterSession
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => $base,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZDEV'.random_int(100000, 999999),
            'name' => 'ZZDEV Cliente devoluciones',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZDEV'.random_int(10000, 99999),
            'name' => 'ZZDEV Producto devoluciones',
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
