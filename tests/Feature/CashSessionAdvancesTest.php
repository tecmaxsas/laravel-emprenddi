<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El cierre de caja tiene que decir cuánta plata hay y de dónde salió.
 *
 * Dos cosas estaban mal y se tapaban entre sí:
 *
 *  1. Al aplicar un anticipo a una factura, el pago se guarda con el método
 *     `advance`. El desglose del turno mostraba entonces una sola fila
 *     «Anticipo del cliente» que se comía casi todo, en vez de los métodos
 *     reales con los que la empresa cobra.
 *
 *  2. `customer_advances.cash_register_session_id` existía y nunca se
 *     llenaba. Un anticipo cobrado en efectivo no entraba en «Esperado en
 *     caja» por ningún lado: el cajero aparecía sobrando al arquear, por el
 *     monto exacto de los anticipos en efectivo del turno.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CashSessionAdvancesTest extends TestCase
{
    private Company $company;

    private User $user;

    private Location $sede;

    private CashRegisterSession $turno;

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

        $this->turno = $this->abrirCaja();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /**
     * Un anticipo en efectivo entra al esperado en caja.
     *
     * Esa plata está físicamente en el cajón. No contarla hacía que el cajero
     * apareciera sobrando al arquear, por el monto exacto de los anticipos.
     */
    public function test_un_anticipo_en_efectivo_entra_al_esperado(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $this->anticipo('cash', 200000);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(round($apertura + 200000, 2), $resumen['expected_cash'],
            'El cliente entregó ese efectivo: está en el cajón.');
    }

    /** Y uno por transferencia no toca el efectivo, pero sí aparece. */
    public function test_un_anticipo_por_transferencia_no_toca_el_efectivo(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $this->anticipo('bank_transfer', 500000);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame($apertura, $resumen['expected_cash'],
            'Una transferencia no pone billetes en el cajón.');

        $this->assertSame(500000.0, $resumen['sales']['by_method']['bank_transfer'] ?? null,
            'Pero sí entró plata y el turno tiene que mostrarla.');
    }

    /**
     * Aplicar un anticipo a una factura no vuelve a contar esa plata.
     *
     * La aplicación cruza el pasivo del anticipo contra la cartera: no mueve
     * dinero. Contarla sería sumar dos veces lo mismo.
     */
    public function test_aplicar_un_anticipo_no_cuenta_dos_veces(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $anticipo = $this->anticipo('cash', 300000);
        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'advance', 300000, $anticipo->id);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(round($apertura + 300000, 2), $resumen['expected_cash'],
            'Los 300.000 entraron una sola vez: al recibir el anticipo.');
    }

    /** Y el desglose muestra el método real, no «Anticipo del cliente». */
    public function test_el_desglose_no_muestra_anticipo_del_cliente(): void
    {
        $anticipo = $this->anticipo('bank_transfer', 400000);
        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'advance', 400000, $anticipo->id);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertArrayNotHasKey('advance', $resumen['sales']['by_method'],
            'Una fila «Anticipo del cliente» se come el desglose y no dice cómo pagó el cliente.');

        $this->assertSame(400000.0, $resumen['sales']['by_method']['bank_transfer'] ?? null);
    }

    /** Un cobro directo sigue contando igual que siempre. */
    public function test_un_cobro_directo_sigue_igual(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $factura = $this->facturaContabilizada();
        $this->pago($factura, 'cash', 75000);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(round($apertura + 75000, 2), $resumen['expected_cash']);
        $this->assertSame(75000.0, $resumen['sales']['by_method']['cash'] ?? null);
    }

    /**
     * Un anticipo sin método guardado no se da por efectivo.
     *
     * Suponer efectivo cuando no se sabe descuadra el arqueo en la dirección
     * peligrosa: el sistema diría que hay más plata de la que hay.
     */
    public function test_un_anticipo_sin_metodo_no_se_da_por_efectivo(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $this->anticipo(null, 600000);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame($apertura, $resumen['expected_cash'],
            'Si no se sabe cómo entró, no se puede afirmar que está en el cajón.');
        $this->assertSame(600000.0, $resumen['sales']['by_method']['other'] ?? null);
    }

    /** Un anticipo de otro turno no se mete en este. */
    public function test_un_anticipo_de_otro_turno_no_cuenta_aqui(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $otro = $this->abrirCaja();
        $this->anticipo('cash', 900000, $otro->id);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame($apertura, $resumen['expected_cash'],
            'Esa plata entró en otro turno y ya se contó allá.');
    }

    // ------------------------------------------- abonos de facturas viejas

    /**
     * Un abono de hoy entra a la caja de hoy, no a la de la factura.
     *
     * El pago heredaba la sesión de la factura. Para una venta del POS da
     * igual —la factura y su pago nacen en el mismo turno—, pero un cliente
     * que viene hoy a pagar una factura de la semana pasada entregaba su plata
     * y el cobro aterrizaba en un turno ya cerrado. El cajero recibía los
     * billetes y el arqueo no los pedía.
     */
    public function test_un_abono_de_hoy_entra_a_la_caja_de_hoy(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        // Una factura de otro turno, ya cerrado.
        $viejo = $this->abrirCaja();
        $viejo->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

        $factura = $this->facturaContabilizada();
        $factura->update(['cash_register_session_id' => $viejo->id]);

        app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => 250000,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => DB::table('payments')
            ->where('paymentable_id', $factura->id)->delete();

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(round($apertura + 250000, 2), $resumen['expected_cash'],
            'El cliente entregó ese efectivo hoy: está en el cajón de hoy.');
    }

    /**
     * Y un abono sobre una factura que nunca pasó por el POS también.
     *
     * Una factura hecha desde el listado no tiene sesión de caja. Su pago
     * quedaba con `cash_register_session_id` en null y no aparecía en ninguna
     * caja, aunque el cajero hubiera recibido los billetes.
     */
    public function test_un_abono_de_una_factura_sin_caja_tambien_entra(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $factura = $this->facturaContabilizada();
        $factura->update(['cash_register_session_id' => null]);

        app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => 180000,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => DB::table('payments')
            ->where('paymentable_id', $factura->id)->delete();

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(round($apertura + 180000, 2), $resumen['expected_cash'],
            'Facturar por fuera del POS no vuelve invisible la plata que entra.');
    }

    /** Y el desglose lo muestra con su método. */
    public function test_el_abono_aparece_con_su_metodo_en_el_desglose(): void
    {
        $factura = $this->facturaContabilizada();
        $factura->update(['cash_register_session_id' => null]);

        app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => 90000,
            'payment_method' => 'bank_transfer',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => DB::table('payments')
            ->where('paymentable_id', $factura->id)->delete();

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(90000.0, $resumen['sales']['by_method']['bank_transfer'] ?? null);
    }

    // --------------------------------------------------------- auxiliares

    private function abrirCaja(): CashRegisterSession
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 100000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function anticipo(?string $metodo, float $monto, ?int $turnoId = null): CustomerAdvance
    {
        $anticipo = CustomerAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $turnoId ?? $this->turno->id,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'applied_amount' => 0,
            'payment_method' => $metodo,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        return $anticipo;
    }

    private function tercero(): ThirdParty
    {
        return ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
    }

    private function facturaContabilizada(): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $this->turno->id,
            'prefix' => 'ZZCJ',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_POSTED,
            'subtotal' => 400000,
            'tax_total' => 0,
            'total' => 400000,
            'net_payable' => 400000,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }

    private function pago(SaleInvoice $factura, string $metodo, float $monto, ?int $anticipoId = null): void
    {
        $pago = Payment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'paymentable_type' => SaleInvoice::class,
            'paymentable_id' => $factura->id,
            'third_party_id' => $factura->third_party_id,
            'customer_advance_id' => $anticipoId,
            'cash_register_session_id' => $this->turno->id,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'payment_method' => $metodo,
            'account_id' => $this->cuentaDeCaja(),
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('payments')->where('id', $pago->id)->delete();
    }

    private function cuentaDeCaja(): ?int
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('accepts_movements', true)
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->value('id');
    }
}
