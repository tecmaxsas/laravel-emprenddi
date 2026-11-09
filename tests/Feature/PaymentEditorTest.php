<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PurchaseInvoiceResource\Pages\ViewPurchaseInvoice;
use App\Filament\App\Resources\PurchaseInvoiceResource\RelationManagers\PaymentsRelationManager as PagosDeCompra;
use App\Filament\App\Resources\SaleInvoiceResource\Pages\ViewSaleInvoice;
use App\Filament\App\Resources\SaleInvoiceResource\RelationManagers\PaymentsRelationManager as PagosDeVenta;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Cash\CashSessionSummary;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Services\Sales\PaymentEditor;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Corregir un cobro ya registrado, en ventas y en compras.
 *
 * Hasta ahora un cobro mal digitado solo se podia borrar y volver a crear a
 * mano, en dos pasos y con el riesgo de que alguien se quedara en la mitad: la
 * factura sin cobro y el usuario creyendo que ya lo habia arreglado.
 *
 * Corregir es reemplazar —no editar la fila— y lo que se prueba aqui es que el
 * reemplazo deje las cuatro cosas que un cobro arrastra en su sitio: el saldo
 * de la factura, el asiento, el arqueo del turno, y la traza de que paso.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PaymentEditorTest extends TestCase
{
    private Company $empresa;

    private User $usuario;

    private Location $sede;

    private CashRegisterSession $turno;

    private PaymentEditor $editor;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);
        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->turno = $this->abrirCaja();
        $this->editor = app(PaymentEditor::class);
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

    // ------------------------------------------------------------- ventas

    /** Bajar el valor de un cobro deja la factura parcial. */
    public function test_corregir_a_la_baja_devuelve_saldo_a_la_factura(): void
    {
        $factura = $this->ventaContabilizada(400000);
        $pago = $this->cobrar($factura, 400000);

        $this->assertSame(SaleInvoice::PAYMENT_PAGADO, $factura->fresh()->payment_status);

        $nuevo = $this->corregir($pago, ['amount' => 250000]);

        $fresca = $factura->fresh();

        $this->assertEqualsWithDelta(250000, (float) $nuevo->amount, 0.01);
        $this->assertEqualsWithDelta(250000, (float) $fresca->paid_amount, 0.01);
        $this->assertEqualsWithDelta(150000, (float) $fresca->balance, 0.01);
        $this->assertSame(SaleInvoice::PAYMENT_PARCIAL, $fresca->payment_status);
    }

    /**
     * Subir el valor por encima del cobro anterior.
     *
     * Es el caso que delata si el reemplazo se hizo mal: el motor valida que
     * el cobro no exceda el saldo pendiente, y ese saldo solo da de si cuando
     * el cobro viejo ya se quito. Si se validara contra la factura sin
     * refrescar, corregir $200.000 a $350.000 seria imposible.
     */
    public function test_corregir_al_alza_usa_el_saldo_que_libera_el_cobro_viejo(): void
    {
        $factura = $this->ventaContabilizada(400000);
        $pago = $this->cobrar($factura, 200000);

        $nuevo = $this->corregir($pago, ['amount' => 350000]);

        $this->assertEqualsWithDelta(350000, (float) $nuevo->amount, 0.01);
        $this->assertEqualsWithDelta(350000, (float) $factura->fresh()->paid_amount, 0.01);
    }

    /** Tambien cambia la forma de pago, que es el otro error tipico. */
    public function test_corregir_cambia_la_forma_de_pago(): void
    {
        $factura = $this->ventaContabilizada(100000);
        $pago = $this->cobrar($factura, 100000);

        $nuevo = $this->corregir($pago, [
            'amount' => 100000,
            'payment_method' => 'bank_transfer',
            'reference' => 'TRF-9981',
        ]);

        $this->assertSame('bank_transfer', $nuevo->payment_method);
        $this->assertSame('TRF-9981', $nuevo->reference);
    }

    /**
     * El cobro viejo no se destruye: queda borrado y el nuevo dice de donde
     * viene.
     *
     * Sin esa traza, quien revise la factura dentro de seis meses ve un cobro
     * borrado y otro nuevo sin ninguna relacion entre los dos.
     */
    public function test_queda_la_traza_de_la_correccion(): void
    {
        $factura = $this->ventaContabilizada(120000);
        $pago = $this->cobrar($factura, 120000);

        $nuevo = $this->corregir($pago, ['amount' => 90000]);

        $viejo = Payment::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($pago->id);

        $this->assertTrue($viejo->trashed(), 'El cobro viejo se marca borrado, no desaparece.');
        $this->assertStringContainsString('correcci', (string) $viejo->description);
        $this->assertStringContainsString("#{$pago->id}", (string) $nuevo->description);
    }

    /** El asiento viejo se reversa y el cobro corregido genera el suyo. */
    public function test_el_asiento_viejo_se_reversa_y_el_nuevo_nace_con_el_suyo(): void
    {
        $factura = $this->ventaContabilizada(200000);
        $pago = $this->cobrar($factura, 200000);
        $asientoViejo = $pago->journal_entry_id;

        $nuevo = $this->corregir($pago, ['amount' => 150000]);

        $this->assertNotNull(
            JournalEntry::withoutGlobalScope(CompanyScope::class)->find($asientoViejo),
            'El asiento original no se borra: en contabilidad se corrige reversando.',
        );

        $this->assertNotNull($nuevo->journal_entry_id, 'El cobro corregido tiene su propio asiento.');
        $this->assertNotSame($asientoViejo, $nuevo->journal_entry_id);

        $reversa = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->empresa->id)
            ->where('type', 'reversal')
            ->latest('id')
            ->first();

        $this->limpiar[] = function () use ($reversa) {
            DB::table('journal_entry_lines')->where('journal_entry_id', $reversa->id)->delete();
            DB::table('journal_entries')->where('id', $reversa->id)->delete();
        };

        $this->assertEqualsWithDelta(200000, (float) $reversa->total_debit, 0.01,
            'La reversa lleva los débitos y créditos cambiados de lado.');
    }

    /** El arqueo del turno cuenta el valor corregido, no el viejo. */
    public function test_el_turno_cuenta_el_valor_corregido(): void
    {
        $apertura = (float) $this->turno->opening_amount;

        $factura = $this->ventaContabilizada(180000);
        $pago = $this->cobrar($factura, 180000);

        $this->corregir($pago, ['amount' => 60000]);

        $this->assertEqualsWithDelta(
            $apertura + 60000,
            app(CashSessionSummary::class)->compute($this->turno->fresh())['expected_cash'],
            0.01,
            'En el cajón está lo que de verdad entró, no lo que se digitó primero.',
        );
    }

    /**
     * Si el cobro corregido no pasa, el viejo sigue ahi.
     *
     * Es lo unico que no se puede fallar: la factura nunca puede quedarse sin
     * ninguno de los dos cobros.
     */
    public function test_si_la_correccion_no_pasa_el_cobro_viejo_sobrevive(): void
    {
        $factura = $this->ventaContabilizada(300000);
        $pago = $this->cobrar($factura, 300000);

        try {
            // Mas de lo que vale la factura entera.
            $this->editor->replace($pago, ['amount' => 900000, 'payment_method' => 'cash']);
            $this->fail('Un cobro mayor que el saldo tiene que rechazarse.');
        } catch (RuntimeException $e) {
            // Esperado.
        }

        $viejo = Payment::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($pago->id);

        $this->assertFalse($viejo->trashed(), 'La transacción tiene que haber devuelto el cobro.');
        $this->assertEqualsWithDelta(300000, (float) $factura->fresh()->paid_amount, 0.01);
    }

    // ------------------------------------------------------------ guardas

    /** Un turno cerrado ya fue contado y firmado. */
    public function test_no_se_corrige_un_cobro_de_un_turno_cerrado(): void
    {
        $factura = $this->ventaContabilizada(100000);
        $pago = $this->cobrar($factura, 100000);

        $this->turno->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $motivo = $this->editor->motivoParaNoEditar($pago->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('cerrado', $motivo);

        $this->expectException(RuntimeException::class);
        $this->editor->replace($pago->fresh(), ['amount' => 50000]);
    }

    /**
     * Un cobro pagado con saldo a favor no se rehace aqui.
     *
     * El reemplazo naceria como plata nueva en la caja mientras el anticipo
     * recupera su saldo: la misma plata contada dos veces.
     */
    public function test_no_se_corrige_un_cobro_hecho_con_anticipo(): void
    {
        $factura = $this->ventaContabilizada(120000);
        $anticipo = $this->anticipo(120000);
        $pago = $this->cobrar($factura, 120000, $anticipo->id);

        $motivo = $this->editor->motivoParaNoEditar($pago->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('saldo a favor', $motivo);
    }

    // ------------------------------------------------------------ compras

    /** Un pago a proveedor se corrige igual. */
    public function test_corrige_un_pago_de_compra(): void
    {
        $compra = $this->compraContabilizada(500000);
        $pago = $this->pagarCompra($compra, 500000);

        $nuevo = $this->corregir($pago, ['amount' => 320000]);

        $fresca = $compra->fresh();

        $this->assertEqualsWithDelta(320000, (float) $nuevo->amount, 0.01);
        $this->assertEqualsWithDelta(320000, (float) $fresca->paid_amount, 0.01);
        $this->assertEqualsWithDelta(180000, (float) $fresca->balance, 0.01);
        $this->assertSame(PurchaseInvoice::PAYMENT_PARCIAL, $fresca->payment_status);
    }

    /**
     * Sin caja abierta no se corrige un pago a proveedor.
     *
     * Esa plata sale del cajón, y el motor de compras exige turno abierto para
     * volver a registrarla. Mejor decirlo antes que reventar después de haber
     * borrado el pago viejo.
     */
    public function test_sin_caja_abierta_no_se_corrige_un_pago_de_compra(): void
    {
        $compra = $this->compraContabilizada(200000);
        $pago = $this->pagarCompra($compra, 200000);

        // El turno se cierra DESPUÉS de cobrar, y el pago queda sin turno
        // propio para que lo que bloquee sea la falta de caja, no la guarda
        // del turno cerrado.
        DB::table('payments')->where('id', $pago->id)->update(['cash_register_session_id' => null]);
        $this->turno->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $motivo = $this->editor->motivoParaNoEditar($pago->fresh());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('caja', $motivo);
    }

    // ---------------------------------------------------------- pantalla

    /**
     * El boton existe y funciona desde la factura de venta.
     *
     * Las pruebas de arriba llaman al servicio directamente, y eso deja un
     * hueco: un servicio impecable que ninguna pantalla sepa invocar sirve de
     * poco. Esto pasa por el boton de verdad.
     */
    public function test_se_corrige_desde_la_pantalla_de_la_venta(): void
    {
        $factura = $this->ventaContabilizada(300000);
        $pago = $this->cobrar($factura, 300000);

        Livewire::test(PagosDeVenta::class, [
            'ownerRecord' => $factura->fresh(),
            'pageClass' => ViewSaleInvoice::class,
        ])
            ->assertOk()
            ->callTableAction('editPayment', $pago, [
                'date' => now()->toDateString(),
                'amount' => 175000,
                'payment_method' => 'cash',
                'account_id' => $this->cuentaDeCaja(),
            ])
            ->assertHasNoTableActionErrors();

        $this->recogerElCobroVivoDe($factura);

        $this->assertEqualsWithDelta(175000, (float) $factura->fresh()->paid_amount, 0.01);
    }

    /** Y desde la factura de compra. */
    public function test_se_corrige_desde_la_pantalla_de_la_compra(): void
    {
        $compra = $this->compraContabilizada(400000);
        $pago = $this->pagarCompra($compra, 400000);

        Livewire::test(PagosDeCompra::class, [
            'ownerRecord' => $compra->fresh(),
            'pageClass' => ViewPurchaseInvoice::class,
        ])
            ->assertOk()
            ->callTableAction('editPayment', $pago, [
                'date' => now()->toDateString(),
                'amount' => 220000,
                'payment_method' => 'cash',
                'account_id' => $this->cuentaDeCaja(),
            ])
            ->assertHasNoTableActionErrors();

        $this->recogerElCobroVivoDe($compra);

        $this->assertEqualsWithDelta(220000, (float) $compra->fresh()->paid_amount, 0.01);
    }

    /** Borrar tambien sigue funcionando desde la pantalla. */
    public function test_se_borra_desde_la_pantalla_de_la_venta(): void
    {
        $factura = $this->ventaContabilizada(90000);
        $pago = $this->cobrar($factura, 90000);

        Livewire::test(PagosDeVenta::class, [
            'ownerRecord' => $factura->fresh(),
            'pageClass' => ViewSaleInvoice::class,
        ])
            ->assertOk()
            ->callTableAction('deletePayment', $pago, ['motivo' => 'ZZ prueba'])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsWithDelta(0, (float) $factura->fresh()->paid_amount, 0.01);
    }

    // --------------------------------------------------------- auxiliares

    /**
     * Apunta a borrar el cobro que dejo la pantalla.
     *
     * Las pruebas que llaman al servicio reciben el pago nuevo y lo registran
     * para limpiarlo; las que pasan por la pantalla no, asi que hay que ir a
     * buscarlo.
     */
    private function recogerElCobroVivoDe(SaleInvoice|PurchaseInvoice $factura): void
    {
        Payment::withoutGlobalScope(CompanyScope::class)
            ->where('paymentable_type', $factura::class)
            ->where('paymentable_id', $factura->id)
            ->get()
            ->each(fn (Payment $p) => $this->olvidar($p));

        JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->empresa->id)
            ->where('type', 'reversal')
            ->where('reference', 'like', '%ZZPE%')
            ->get()
            ->each(function (JournalEntry $asiento) {
                $this->limpiar[] = function () use ($asiento) {
                    DB::table('journal_entry_lines')->where('journal_entry_id', $asiento->id)->delete();
                    DB::table('journal_entries')->where('id', $asiento->id)->delete();
                };
            });
    }

    /** @param array<string, mixed> $cambios */
    private function corregir(Payment $pago, array $cambios): Payment
    {
        $nuevo = $this->editor->replace($pago->fresh(), [
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            ...$cambios,
        ]);

        $this->limpiar[] = function () use ($nuevo) {
            $asiento = $nuevo->journal_entry_id;
            DB::table('payments')->where('id', $nuevo->id)->delete();
            if ($asiento) {
                DB::table('journal_entry_lines')->where('journal_entry_id', $asiento)->delete();
                DB::table('journal_entries')->where('id', $asiento)->delete();
            }
        };

        return $nuevo->fresh();
    }

    private function abrirCaja(): CashRegisterSession
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('cashier_user_id', $this->usuario->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
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
            'cashier_user_id' => $this->usuario->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHour(),
            'opening_amount' => 2000000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function tercero(): ThirdParty
    {
        return ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();
    }

    private function ventaContabilizada(float $total): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $this->turno->id,
            'prefix' => 'ZZPE',
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

    private function compraContabilizada(float $total): PurchaseInvoice
    {
        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero()->id,
            'prefix' => 'ZZPE',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => PurchaseInvoice::STATUS_POSTED,
            'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('purchase_invoices')->where('id', $factura->id)->delete();

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

        $this->olvidar($pago);

        return $pago->fresh();
    }

    private function pagarCompra(PurchaseInvoice $factura, float $monto): Payment
    {
        $pago = app(PurchaseInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => $monto,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->olvidar($pago);

        return $pago->fresh();
    }

    private function olvidar(Payment $pago): void
    {
        $this->limpiar[] = function () use ($pago) {
            $asiento = $pago->journal_entry_id;
            DB::table('payments')->where('id', $pago->id)->delete();
            if ($asiento) {
                DB::table('journal_entry_lines')->where('journal_entry_id', $asiento)->delete();
                DB::table('journal_entries')->where('id', $asiento)->delete();
            }
        };
    }

    private function anticipo(float $monto): CustomerAdvance
    {
        $anticipo = CustomerAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => $this->turno->id,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'applied_amount' => 0,
            'payment_method' => 'cash',
            'created_by_user_id' => $this->usuario->id,
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        return $anticipo;
    }

    private function cuentaDeCaja(): ?int
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('accepts_movements', true)
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->value('id');
    }
}
