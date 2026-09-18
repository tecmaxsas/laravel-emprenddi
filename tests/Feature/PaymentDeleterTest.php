<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Sales\PaymentDeleter;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Borrar un cobro registrado por error.
 *
 * Un pago mal digitado —el monto equivocado, el método equivocado, o dos veces
 * el mismo— solo se podía deshacer borrando la factura entera. Eso obliga a
 * rehacer la venta y, si ya fue a la DIAN, ni siquiera es posible.
 *
 * Un cobro no está solo: arrastra un asiento contable, a veces el saldo a
 * favor de un cliente, el saldo de la factura y el arqueo del turno. Lo que se
 * prueba aquí es que las cuatro cosas queden como estaban.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PaymentDeleterTest extends TestCase
{
    private Company $company;

    private User $user;

    private Location $sede;

    private CashRegisterSession $turno;

    private PaymentDeleter $borrador;

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
        $this->borrador = app(PaymentDeleter::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------------ lo que deshace

    /** El saldo de la factura vuelve a subir. */
    public function test_la_factura_vuelve_a_quedar_con_saldo(): void
    {
        $factura = $this->facturaContabilizada(400000);
        $pago = $this->cobrar($factura, 400000);

        $this->assertSame(SaleInvoice::PAYMENT_PAGADO, $factura->fresh()->payment_status);

        $this->borrador->delete($pago);

        $fresca = $factura->fresh();

        $this->assertSame(0.0, (float) $fresca->paid_amount);
        $this->assertNotSame(SaleInvoice::PAYMENT_PAGADO, $fresca->payment_status,
            'Si el estado se queda en «pagado», la factura desaparece de la cartera.');
    }

    /** Con dos cobros, borrar uno deja la factura parcial. */
    public function test_borrar_un_cobro_de_dos_deja_la_factura_parcial(): void
    {
        $factura = $this->facturaContabilizada(400000);
        $primero = $this->cobrar($factura, 150000);
        $this->cobrar($factura, 250000);

        $this->borrador->delete($primero);

        $fresca = $factura->fresh();

        $this->assertSame(250000.0, (float) $fresca->paid_amount);
        $this->assertSame(SaleInvoice::PAYMENT_PARCIAL, $fresca->payment_status);
    }

    /**
     * El asiento se reversa, no se borra.
     *
     * Un asiento que desaparece deja el libro mayor de la cuenta de caja con
     * un movimiento menos y sin ninguna traza de por qué.
     */
    public function test_el_asiento_se_reversa_y_el_original_se_queda(): void
    {
        $factura = $this->facturaContabilizada(200000);
        $pago = $this->cobrar($factura, 200000);

        $asientoOriginal = $pago->journal_entry_id;
        $this->assertNotNull($asientoOriginal, 'El cobro tiene que haber generado su asiento.');

        $this->borrador->delete($pago);

        $this->assertNotNull(
            JournalEntry::withoutGlobalScope(CompanyScope::class)->find($asientoOriginal),
            'El asiento original no se borra: en contabilidad se corrige reversando.',
        );

        $reversa = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->company->id)
            ->where('type', 'reversal')
            ->latest('id')
            ->first();

        $this->limpiar[] = fn () => DB::table('journal_entries')->where('id', $reversa->id)->delete();

        $original = JournalEntry::withoutGlobalScope(CompanyScope::class)->find($asientoOriginal);

        $this->assertEquals((float) $original->total_debit, (float) $reversa->total_credit,
            'La reversa lleva los débitos y créditos cambiados de lado.');
        $this->assertEquals((float) $original->total_credit, (float) $reversa->total_debit);
    }

    /** Un cobro hecho con un anticipo devuelve ese saldo a favor. */
    public function test_un_cobro_de_anticipo_devuelve_el_saldo_a_favor(): void
    {
        $factura = $this->facturaContabilizada(300000);
        $anticipo = $this->anticipo(300000);

        $pago = $this->cobrar($factura, 300000, $anticipo->id);
        $anticipo->update(['applied_amount' => 300000]);

        $this->borrador->delete($pago);

        $this->assertSame(0.0, (float) $anticipo->fresh()->applied_amount,
            'Esa plata sigue siendo del cliente: tiene que volver a quedar disponible.');
    }

    /** El anticipo nunca queda en negativo aunque el dato venga torcido. */
    public function test_el_anticipo_no_queda_en_negativo(): void
    {
        $factura = $this->facturaContabilizada(300000);
        $anticipo = $this->anticipo(300000);

        $pago = $this->cobrar($factura, 300000, $anticipo->id);
        $anticipo->update(['applied_amount' => 0]);

        $this->borrador->delete($pago);

        $this->assertSame(0.0, (float) $anticipo->fresh()->applied_amount,
            'En negativo, el cliente aparecería con más saldo del que tiene.');
    }

    /** El turno de caja deja de contarlo. */
    public function test_el_turno_deja_de_contar_el_cobro(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $factura = $this->facturaContabilizada(180000);
        $pago = $this->cobrar($factura, 180000);

        $this->assertSame(round($apertura + 180000, 2),
            app(CashSessionSummary::class)->compute($this->turno->fresh())['expected_cash']);

        $this->borrador->delete($pago);

        $this->assertSame($apertura,
            app(CashSessionSummary::class)->compute($this->turno->fresh())['expected_cash'],
            'Ese efectivo ya no está en el cajón.');
    }

    /** El pago no se destruye: queda para la auditoría. */
    public function test_el_pago_queda_marcado_y_con_el_motivo(): void
    {
        $factura = $this->facturaContabilizada(90000);
        $pago = $this->cobrar($factura, 90000);

        $this->borrador->delete($pago, 'Se registró dos veces');

        $enBase = Payment::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($pago->id);

        $this->assertNotNull($enBase, 'La fila se queda: la auditoría tiene que poder leerla.');
        $this->assertTrue($enBase->trashed());
        $this->assertStringContainsString('Se registró dos veces', (string) $enBase->description);
    }

    // ------------------------------------------------------------ guardas

    /**
     * Un turno ya cerrado no se toca.
     *
     * Fue contado y firmado. Quitarle un cobro deja el cuadre mentiroso para
     * siempre y nadie lo nota hasta la auditoría.
     */
    public function test_no_se_borra_un_cobro_de_un_turno_cerrado(): void
    {
        $factura = $this->facturaContabilizada(100000);
        $pago = $this->cobrar($factura, 100000);

        $this->turno->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->assertNotNull($this->borrador->motivoParaNoBorrar($pago->fresh()));

        $this->expectException(RuntimeException::class);
        $this->borrador->delete($pago->fresh());
    }

    /** Y uno ya borrado tampoco. */
    public function test_no_se_borra_dos_veces(): void
    {
        $factura = $this->facturaContabilizada(50000);
        $pago = $this->cobrar($factura, 50000);

        $this->borrador->delete($pago);

        $this->assertNotNull(
            $this->borrador->motivoParaNoBorrar(
                Payment::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($pago->id)
            )
        );
    }

    /** La confirmación dice qué va a pasar antes de hacerlo. */
    public function test_se_explican_las_consecuencias_antes(): void
    {
        $factura = $this->facturaContabilizada(120000);
        $anticipo = $this->anticipo(120000);
        $pago = $this->cobrar($factura, 120000, $anticipo->id);

        $consecuencias = implode(' ', $this->borrador->consecuencias($pago));

        $this->assertStringContainsString('saldo pendiente', $consecuencias);
        $this->assertStringContainsString('saldo a favor', $consecuencias);
        $this->assertStringContainsString('turno de caja', $consecuencias);
    }

    // --------------------------------------------------------- auxiliares

    private function abrirCaja(): CashRegisterSession
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHour(),
            'opening_amount' => 100000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function tercero(): ThirdParty
    {
        return ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
    }

    private function facturaContabilizada(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $this->turno->id,
            'prefix' => 'ZZPD',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => SaleInvoice::STATUS_POSTED,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'net_payable' => $total,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }

    /** Cobra por el motor, para que el pago nazca igual que en la pantalla. */
    private function cobrar(SaleInvoice $factura, float $monto, ?int $anticipoId = null): Payment
    {
        $pago = app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => $monto,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        if ($anticipoId) {
            $pago->update(['customer_advance_id' => $anticipoId]);
        }

        $this->limpiar[] = function () use ($pago) {
            $asiento = $pago->journal_entry_id;
            DB::table('payments')->where('id', $pago->id)->delete();
            if ($asiento) {
                DB::table('journal_entry_lines')->where('journal_entry_id', $asiento)->delete();
                DB::table('journal_entries')->where('id', $asiento)->delete();
            }
        };

        return $pago->fresh();
    }

    private function anticipo(float $monto): CustomerAdvance
    {
        $anticipo = CustomerAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $this->turno->id,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'applied_amount' => 0,
            'payment_method' => 'cash',
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        return $anticipo;
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
