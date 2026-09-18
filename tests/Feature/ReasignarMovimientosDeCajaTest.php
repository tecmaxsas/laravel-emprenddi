<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reparar la caja de lo que ya quedó mal registrado.
 *
 * Los arreglos al motor aplican de aquí en adelante; los cobros y anticipos
 * que ya estaban mal atribuidos siguen igual. Este comando los pone en su
 * turno usando la fecha en que se registraron.
 *
 * Toca datos que ya existen, así que lo que más importa es lo que NO hace:
 * sin `--aplicar` no escribe nada, no se inventa la empresa cuando el nombre
 * es ambiguo, y no mueve lo que ya estaba bien.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ReasignarMovimientosDeCajaTest extends TestCase
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

        // Se cierra cualquier otro turno abierto de la empresa para que el
        // comando no tenga que elegir entre varios.
        $this->cerrarTurnosAbiertos();

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

    /** La simulación no escribe nada. */
    public function test_sin_aplicar_no_toca_nada(): void
    {
        $anticipo = $this->anticipoSuelto('cash', 300000);

        $this->artisan('caja:reasignar', ['--empresa' => $this->company->id])
            ->expectsOutputToContain('SIMULACIÓN')
            ->assertSuccessful();

        $this->assertNull($anticipo->fresh()->cash_register_session_id,
            'Una simulación que escribe no es una simulación.');
    }

    /** Y muestra cuánto es lo que está suelto. */
    public function test_la_simulacion_dice_cuanto_hay_suelto(): void
    {
        $this->anticipoSuelto('cash', 300000);

        $this->artisan('caja:reasignar', ['--empresa' => $this->company->id])
            ->expectsOutputToContain('Anticipos sin turno')
            ->expectsOutputToContain('300.000')
            ->assertSuccessful();
    }

    /** Con --aplicar, el anticipo queda en el turno. */
    public function test_aplicar_asigna_el_anticipo(): void
    {
        $anticipo = $this->anticipoSuelto('cash', 300000);

        $this->artisan('caja:reasignar', [
            '--empresa' => $this->company->id,
            '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertSame($this->turno->id, $anticipo->fresh()->cash_register_session_id);
    }

    /** Y el pago mal atribuido también. */
    public function test_aplicar_asigna_el_pago_de_otro_turno(): void
    {
        $viejo = $this->abrirCaja();
        $viejo->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

        $pago = $this->pagoEnTurno($viejo->id, 'cash', 150000);

        $this->artisan('caja:reasignar', [
            '--empresa' => $this->company->id,
            '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertSame($this->turno->id, $pago->fresh()->cash_register_session_id);
    }

    /**
     * Una aplicación de anticipo no se mueve.
     *
     * Esa operación no mueve dinero y el resumen del turno ya no la cuenta.
     * Reasignarla sería ruido sobre un dato que no se usa.
     */
    public function test_no_mueve_las_aplicaciones_de_anticipo(): void
    {
        $anticipo = $this->anticipoSuelto('cash', 200000);
        $pago = $this->pagoEnTurno(null, 'advance', 200000, $anticipo->id);

        $this->artisan('caja:reasignar', [
            '--empresa' => $this->company->id,
            '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertNull($pago->fresh()->cash_register_session_id);
    }

    /** Lo que ya estaba bien no se toca. */
    public function test_no_toca_lo_que_ya_estaba_bien(): void
    {
        $pago = $this->pagoEnTurno($this->turno->id, 'cash', 50000);
        $actualizado = $pago->updated_at;

        $this->artisan('caja:reasignar', [
            '--empresa' => $this->company->id,
            '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertEquals($actualizado, $pago->fresh()->updated_at);
    }

    /** Un movimiento anterior a la apertura del turno no entra. */
    public function test_no_se_lleva_lo_de_antes_de_abrir(): void
    {
        $anticipo = $this->anticipoSuelto('cash', 400000);
        DB::table('customer_advances')->where('id', $anticipo->id)
            ->update(['created_at' => now()->subDays(10)]);

        $this->artisan('caja:reasignar', [
            '--empresa' => $this->company->id,
            '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertNull($anticipo->fresh()->cash_register_session_id,
            'Esa plata entró antes de que este turno existiera.');
    }

    /** Un nombre que no existe no repara nada. */
    public function test_una_empresa_que_no_existe_falla(): void
    {
        $this->artisan('caja:reasignar', ['--empresa' => 'ZZ-no-existe-ZZ'])
            ->expectsOutputToContain('No hay ninguna empresa')
            ->assertFailed();
    }

    /** Sin empresa tampoco hace nada. */
    public function test_sin_empresa_falla(): void
    {
        $this->artisan('caja:reasignar')
            ->expectsOutputToContain('Falta --empresa')
            ->assertFailed();
    }

    /** Se puede buscar la empresa por nombre. */
    public function test_encuentra_la_empresa_por_nombre(): void
    {
        $this->anticipoSuelto('cash', 10000);

        $this->artisan('caja:reasignar', ['--empresa' => $this->company->name])
            ->expectsOutputToContain($this->company->name)
            ->assertSuccessful();
    }

    // --------------------------------------------------------- auxiliares

    private function cerrarTurnosAbiertos(): void
    {
        $abiertos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($abiertos->isEmpty()) {
            return;
        }

        DB::table('cash_register_sessions')->whereIn('id', $abiertos)
            ->update(['status' => CashRegisterSession::STATUS_CLOSED]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')
            ->whereIn('id', $abiertos)
            ->update(['status' => CashRegisterSession::STATUS_OPEN]);
    }

    private function abrirCaja(): CashRegisterSession
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHours(2),
            'opening_amount' => 100000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function anticipoSuelto(string $metodo, float $monto): CustomerAdvance
    {
        $anticipo = CustomerAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'third_party_id' => $this->tercero()->id,
            'cash_register_session_id' => null,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'applied_amount' => 0,
            'payment_method' => $metodo,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        return $anticipo;
    }

    private function pagoEnTurno(?int $turnoId, string $metodo, float $monto, ?int $anticipoId = null): Payment
    {
        $factura = $this->factura();

        $pago = Payment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'paymentable_type' => SaleInvoice::class,
            'paymentable_id' => $factura->id,
            'third_party_id' => $factura->third_party_id,
            'customer_advance_id' => $anticipoId,
            'cash_register_session_id' => $turnoId,
            'date' => now()->toDateString(),
            'amount' => $monto,
            'payment_method' => $metodo,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('payments')->where('id', $pago->id)->delete();

        return $pago;
    }

    private function tercero(): ThirdParty
    {
        return ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
    }

    private function factura(): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->tercero()->id,
            'prefix' => 'ZZRE',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_POSTED,
            'subtotal' => 500000,
            'tax_total' => 0,
            'total' => 500000,
            'net_payable' => 500000,
            'paid_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }
}
