<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\CustomerAdvanceService;
use App\Services\Sales\CustomerCreditGuard;
use App\Services\Sales\CustomerStatement;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cómo se aplican y cómo se muestran los abonos del cliente.
 *
 * **Dos cosas estaban mal, y las dos se notaban al mirar la hoja del cliente.**
 *
 * 1. El abono se aplicaba SOLO a las facturas del sistema y saltaba por
 *    completo el saldo de apertura —lo que el cliente ya debía al migrar—. Un
 *    cliente podía abonar durante meses y el renglón más antiguo seguía
 *    intacto, que es justo el que lleva más tiempo sin cobrarse.
 *
 * 2. El anticipo se mostraba por lo que quedaba sin usar, no por su valor. Uno
 *    de $2.000.000 con $1.500.000 ya aplicados aparecía como «$500.000» y el
 *    cliente no encontraba sus dos millones por ningún lado. El saldo corrido
 *    salía bien; lo que no salía era la explicación.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class EstadoDeCuentaAbonosTest extends TestCase
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
    /*  A qué se aplica el abono */
    /* ------------------------------------------------------------------ */

    /**
     * El abono toca PRIMERO el saldo de apertura.
     *
     * Es la deuda más vieja del cliente. Dejarla para el final es dejar sin
     * cobrar justo lo que lleva más tiempo pendiente.
     */
    public function test_el_abono_va_primero_al_saldo_de_apertura(): void
    {
        $this->conSaldoDeApertura(300000);
        $this->facturaPendiente(200000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 300000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $fresco = $this->cliente->fresh();

        $this->assertEqualsWithDelta(300000, (float) $fresco->opening_balance_applied, 0.01,
            'Los $300.000 tenían que cerrar la deuda vieja antes de tocar la factura.');
        $this->assertEqualsWithDelta(0, $fresco->saldoDeAperturaPendiente(), 0.01);
    }

    /** Y lo que sobra sigue a las facturas, de la más vieja a la más nueva. */
    public function test_lo_que_sobra_pasa_a_las_facturas(): void
    {
        $this->conSaldoDeApertura(100000);
        $factura = $this->facturaPendiente(200000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 250000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $this->assertEqualsWithDelta(100000, (float) $this->cliente->fresh()->opening_balance_applied, 0.01);
        $this->assertEqualsWithDelta(150000, (float) $factura->fresh()->paid_amount, 0.01,
            'Los $150.000 restantes van contra la factura.');
    }

    /** El saldo de apertura abonado deja de consumir cupo. */
    public function test_la_apertura_abonada_libera_cupo(): void
    {
        $this->conSaldoDeApertura(300000);

        $antes = app(CustomerCreditGuard::class)->currentDebt($this->cliente);
        $this->assertEqualsWithDelta(300000, $antes, 0.01);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 300000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $this->assertEqualsWithDelta(0, app(CustomerCreditGuard::class)->currentDebt($this->cliente->fresh()), 0.01,
            'Lo abonado contra la deuda vieja no puede seguir consumiendo cupo.');
    }

    /* ------------------------------------------------------------------ */
    /*  Cómo se muestra */
    /* ------------------------------------------------------------------ */

    /**
     * El anticipo se muestra por su valor COMPLETO.
     *
     * Era la queja concreta: $2.000.000 abonados, $1.500.000 aplicados, y en
     * la hoja aparecía «$500.000».
     */
    public function test_el_anticipo_se_muestra_completo(): void
    {
        $this->facturaPendiente(1500000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 2000000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());

        $anticipo = $estado['movements']->firstWhere('type', CustomerStatement::ADVANCE);

        $this->assertNotNull($anticipo);
        $this->assertEqualsWithDelta(2000000, (float) $anticipo['credit'], 0.01,
            'Tiene que verse lo que el cliente entregó, no el resto.');
    }

    /** Y la parte aplicada sale como su débito. */
    public function test_lo_aplicado_sale_como_debito(): void
    {
        $this->facturaPendiente(1500000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 2000000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());

        $aplicacion = $estado['movements']->firstWhere('type', CustomerStatement::APPLICATION);

        $this->assertNotNull($aplicacion, 'Sin la contrapartida, el anticipo completo descuadraría la hoja.');
        $this->assertEqualsWithDelta(1500000, (float) $aplicacion['debit'], 0.01);
    }

    /**
     * Y con todo eso, el saldo final sigue siendo el correcto.
     *
     * Es lo que hace que mostrar los valores completos sea seguro: el par
     * débito/crédito suma cero, así que explica más sin cambiar la cuenta.
     */
    public function test_el_saldo_final_no_cambia(): void
    {
        $this->facturaPendiente(1500000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 2000000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());

        $this->assertEqualsWithDelta(-500000, (float) $estado['due'], 0.01,
            'Pagó $2.000.000 por $1.500.000: le quedan $500.000 a favor.');
    }

    /** El abono a la apertura también se ve, no solo se aplica. */
    public function test_el_abono_a_la_apertura_se_ve_en_la_hoja(): void
    {
        $this->conSaldoDeApertura(300000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 300000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());

        $apertura = $estado['movements']->firstWhere('type', CustomerStatement::OPENING);
        $abono = $estado['movements']->first(
            fn ($m) => $m['type'] === CustomerStatement::APPLICATION && $m['credit'] > 0
        );

        $this->assertEqualsWithDelta(300000, (float) $apertura['debit'], 0.01,
            'La apertura conserva su valor original: es lo que debía al migrar.');
        $this->assertNotNull($abono, 'Y el abono contra ella tiene que verse.');
        $this->assertEqualsWithDelta(300000, (float) $abono['credit'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $estado['due'], 0.01);
    }

    /** Los cobros traen su identificador, para poder corregirlos o borrarlos. */
    public function test_los_cobros_traen_su_identificador(): void
    {
        $factura = $this->facturaPendiente(100000);

        app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'amount' => 40000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());

        $cobro = $estado['movements']->firstWhere('type', CustomerStatement::PAYMENT);

        $this->assertNotNull($cobro);
        $this->assertArrayHasKey('payment_id', $cobro,
            'Sin el id, la hoja no puede ofrecer corregir ni borrar el cobro.');
        $this->assertNotNull($cobro['payment_id']);
    }

    /* ------------------------------------------------------------------ */
    /*  Abono y anticipo no son lo mismo */
    /* ------------------------------------------------------------------ */

    /**
     * El ABONO baja lo que el cliente debe, y no queda a favor.
     *
     * Era la queja concreta: la hoja mostraba «$950.000 a favor» y «$730.000
     * adeudado» al mismo tiempo, y el cliente leía que tenía casi un millón
     * guardado aparte de su deuda. No lo tenía: ese dinero ya había bajado la
     * deuda.
     */
    public function test_el_abono_baja_la_deuda_y_no_queda_a_favor(): void
    {
        $this->conSaldoDeApertura(1000000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 400000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'kind' => CustomerAdvance::ABONO,
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());

        $this->assertEqualsWithDelta(600000, (float) $estado['due'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $estado['advance_balance'], 0.01,
            'El abono bajó la deuda: no puede aparecer además como saldo a favor.');
    }

    /**
     * El ANTICIPO sí queda a favor, y no toca la deuda de hoy.
     *
     * Es plata por una compra que todavía no existe.
     */
    public function test_el_anticipo_queda_a_favor_y_no_toca_la_deuda(): void
    {
        $this->conSaldoDeApertura(1000000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 400000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'kind' => CustomerAdvance::ANTICIPO,
        ]);

        $fresco = $this->cliente->fresh();

        $this->assertEqualsWithDelta(0, (float) $fresco->opening_balance_applied, 0.01,
            'Un anticipo no paga una deuda que ya existía.');
        $this->assertEqualsWithDelta(400000,
            app(CustomerAdvanceService::class)->availableBalance($fresco), 0.01);
    }

    /** Sin decir el tipo, se comporta como abono: es el caso corriente. */
    public function test_sin_tipo_se_comporta_como_abono(): void
    {
        $this->conSaldoDeApertura(500000);

        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 200000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $this->assertEqualsWithDelta(200000, (float) $this->cliente->fresh()->opening_balance_applied, 0.01,
            'Los registros anteriores siempre se aplicaron: cambiarles el significado sería reescribir el pasado.');
    }

    /** La hoja los nombra distinto, que es de donde venía la confusión. */
    public function test_la_hoja_los_nombra_distinto(): void
    {
        app(CustomerAdvanceService::class)->register($this->cliente, [
            'amount' => 100000,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'kind' => CustomerAdvance::ANTICIPO,
        ]);

        $estado = app(CustomerStatement::class)->build($this->cliente->fresh());
        $movimiento = $estado['movements']->firstWhere('type', CustomerStatement::ADVANCE);

        $this->assertStringContainsString('Anticipo', $movimiento['reference']);
        $this->assertSame(CustomerAdvance::ANTICIPO, $movimiento['advance_kind']);
    }

    // --------------------------------------------------------- auxiliares

    private function conSaldoDeApertura(float $monto): void
    {
        $this->cliente->update([
            'opening_balance' => $monto,
            'opening_balance_date' => now()->subMonths(6)->toDateString(),
            'opening_balance_applied' => 0,
        ]);
    }

    private function facturaPendiente(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZABO',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => now()->subDays(10)->toDateString(),
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
                ->where('reference', 'like', '%ZZABO%')
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

        return app(SaleInvoiceEngine::class)->post($factura->fresh(['lines']), allowNegativeStock: true);
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZABO'.random_int(100000, 999999),
            'name' => 'ZZABO Cliente abonos',
            'is_customer' => true,
            'credit_limit' => 5000000,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZABO'.random_int(10000, 99999),
            'name' => 'ZZABO Producto abonos',
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('customer_advances')->where('third_party_id', $this->cliente->id)->delete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }
}
