<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CashSessionGate;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La plata entra a la caja del que la recibe.
 *
 * En un restaurante con tablets hay varias cajas abiertas a la vez: la del
 * mesero que toma el pedido y la del cajero que cobra. El documento nacía con
 * la caja del mesero y el cobro la heredaba, así que el efectivo del cajero
 * aparecía en el arqueo de alguien que nunca tocó un billete. Un día de
 * operación dejó $665.500 en la caja equivocada, y el cajero cerró corto por
 * los $454.500 en efectivo de esa diferencia.
 *
 * Lo que estos tests fijan es la regla, no el síntoma: quien responde por la
 * plata es quien la recibe, sin importar de qué turno venga el documento.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CashSessionAttributionTest extends TestCase
{
    private Company $company;

    /** El que cobra: tiene su caja y es quien está logueado. */
    private User $cajero;

    /** El que toma el pedido en la tablet: tiene caja abierta y no cobra. */
    private User $mesero;

    private Location $sede;

    private CashRegisterSession $cajaDelCajero;

    private CashRegisterSession $cajaDelMesero;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->cajero->company_id);
        $this->actingAs($this->cajero);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->mesero = $this->crearMesero();

        $this->cajaDelCajero = $this->abrirCaja($this->cajero->id, 100000);
        $this->cajaDelMesero = $this->abrirCaja($this->mesero->id, 0);
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
     * El cobro de una factura que nació en la caja del mesero entra a la
     * caja del cajero. Este es exactamente el caso que descuadró el cierre.
     */
    public function test_el_cobro_entra_a_la_caja_del_cajero_no_a_la_del_mesero(): void
    {
        $factura = $this->facturaDe($this->cajaDelMesero);

        $pago = app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => 454500,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => DB::table('payments')
            ->where('paymentable_id', $factura->id)->delete();

        $this->assertSame($this->cajaDelCajero->id, $pago->cash_register_session_id,
            'Los billetes los recibió el cajero: responden en su cajón, no en el del mesero.');
    }

    /** Y el arqueo de cada uno lo refleja: uno los pide, el otro no. */
    public function test_el_arqueo_le_pide_el_efectivo_al_que_lo_recibio(): void
    {
        $apertura = (float) $this->cajaDelCajero->opening_amount;
        $factura = $this->facturaDe($this->cajaDelMesero);

        app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => 454500,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => DB::table('payments')
            ->where('paymentable_id', $factura->id)->delete();

        $resumen = app(CashSessionSummary::class)->compute($this->cajaDelCajero->fresh());
        $delMesero = app(CashSessionSummary::class)->compute($this->cajaDelMesero->fresh());

        $this->assertSame(round($apertura + 454500, 2), $resumen['expected_cash'],
            'El cajero tiene ese efectivo en el cajón y el arqueo tiene que pedírselo.');

        $this->assertSame(0.0, $delMesero['expected_cash'],
            'Al mesero no se le puede exigir plata que nunca recibió.');
    }

    /**
     * Sin caja abierta se conserva el turno del documento.
     *
     * Un administrador registrando un cobro por transferencia desde su
     * escritorio no tiene cajón. Perder la referencia dejaría el pago huérfano.
     */
    public function test_sin_caja_abierta_se_conserva_el_turno_del_documento(): void
    {
        $this->cajaDelCajero->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->assertSame(
            $this->cajaDelMesero->id,
            CashSessionGate::receivingSessionId($this->cajaDelMesero->id),
            'Sin cajón propio, el documento es la única referencia que queda.',
        );
    }

    /** Con caja abierta, el turno del documento no manda. */
    public function test_con_caja_abierta_el_turno_del_documento_no_manda(): void
    {
        $this->assertSame(
            $this->cajaDelCajero->id,
            CashSessionGate::receivingSessionId($this->cajaDelMesero->id),
            'La regla es una sola: la plata entra al cajón que está abierto.',
        );
    }

    // --------------------------------------------------------- auxiliares

    private function crearMesero(): User
    {
        $mesero = User::withoutGlobalScopes()->create([
            'name' => 'ZZ Mesero de prueba',
            'email' => 'zz-mesero-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('users')->where('id', $mesero->id)->delete();

        return $mesero;
    }

    private function abrirCaja(int $userId, float $apertura): CashRegisterSession
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $userId,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => $apertura,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function facturaDe(CashRegisterSession $turno): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $turno->id,
            'prefix' => 'ZZAT',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_POSTED,
            'subtotal' => 454500,
            'tax_total' => 0,
            'total' => 454500,
            'net_payable' => 454500,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }

    private function tercero(): ThirdParty
    {
        return ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
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
