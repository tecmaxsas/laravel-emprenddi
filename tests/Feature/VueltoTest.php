<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentMethodOptions;
use App\Support\Vuelto;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cuánto hay que devolverle al cliente.
 *
 * La resta la hacía el cajero de cabeza. Con un total de $47.300 y un billete
 * de $50.000 no falla nadie; con tres productos, un descuento y $100.000
 * encima del mostrador, sí — y el faltante aparece al arquear, cuando ya nadie
 * sabe en cuál venta fue.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class VueltoTest extends TestCase
{
    private Company $company;

    private User $user;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        PaymentMethodOptions::olvidarCache();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        PaymentMethodOptions::olvidarCache();

        parent::tearDown();
    }

    // ------------------------------------------------------------ la resta

    /** La cuenta de siempre. */
    public function test_calcula_el_vuelto(): void
    {
        $this->assertSame(2700.0, Vuelto::calcular(50000, 47300));
    }

    /**
     * Sin centavos: en Colombia no circulan.
     *
     * Devolver «$1.333,33» es una cifra que el cajero no puede entregar.
     */
    public function test_el_vuelto_se_redondea_a_peso(): void
    {
        $this->assertSame(1333.0, Vuelto::calcular(10000, 8666.67));
    }

    /**
     * Si entregó menos, no hay vuelto negativo.
     *
     * Eso no es un vuelto, es un pago incompleto, y el POS tiene que decirlo
     * como tal en vez de mostrar un número en rojo que nadie sabe interpretar.
     */
    public function test_pagar_de_menos_no_es_un_vuelto_negativo(): void
    {
        $this->assertSame(0.0, Vuelto::calcular(30000, 47300));
        $this->assertSame(17300.0, Vuelto::faltante(30000, 47300));
    }

    /** Pagar justo no deja vuelto ni faltante. */
    public function test_pagar_justo_no_deja_nada(): void
    {
        $this->assertSame(0.0, Vuelto::calcular(47300, 47300));
        $this->assertSame(0.0, Vuelto::faltante(47300, 47300));
    }

    // ------------------------------------------- solo aplica al efectivo

    /** Solo el efectivo tiene vuelto. */
    public function test_solo_el_efectivo_tiene_vuelto(): void
    {
        $this->assertTrue(Vuelto::aplica('cash', $this->company->id));
        $this->assertFalse(Vuelto::aplica('bank_transfer', $this->company->id),
            'Nadie devuelve plata de una transferencia: se cobra el monto exacto.');
        $this->assertFalse(Vuelto::aplica('credit_card', $this->company->id));
        $this->assertFalse(Vuelto::aplica(null, $this->company->id));
    }

    /** Y un método de efectivo propio de la empresa también. */
    public function test_un_efectivo_propio_de_la_empresa_tambien_tiene_vuelto(): void
    {
        $metodo = PaymentMethod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'zz_efectivo_2',
            'name' => 'Efectivo Caja 2',
            'type' => 'cash',
            'active' => true,
            'sort_order' => 99,
        ]);
        $this->limpiar[] = fn () => DB::table('payment_methods')->where('id', $metodo->id)->delete();
        PaymentMethodOptions::olvidarCache();

        $this->assertTrue(Vuelto::aplica('zz_efectivo_2', $this->company->id),
            'Lo que decide es el tipo del método, no su código.');
    }

    // ------------------------------------------------------ lo que se guarda

    /** En efectivo se guardan las dos cifras. */
    public function test_en_efectivo_se_guarda_lo_recibido_y_el_vuelto(): void
    {
        $guardado = Vuelto::paraGuardar('cash', 50000, 47300, $this->company->id);

        $this->assertSame(50000.0, $guardado['cash_received']);
        $this->assertSame(2700.0, $guardado['change_given']);
    }

    /**
     * En los demás métodos no se guarda nada.
     *
     * Guardar ceros ahí haría creer que hubo una entrega que nunca existió.
     */
    public function test_en_los_demas_metodos_no_se_guarda_nada(): void
    {
        $guardado = Vuelto::paraGuardar('bank_transfer', 50000, 47300, $this->company->id);

        $this->assertNull($guardado['cash_received']);
        $this->assertNull($guardado['change_given']);
    }

    /** Sin dato tampoco: el cajero cobró el monto justo y no lo digitó. */
    public function test_sin_dato_no_se_guarda_nada(): void
    {
        $guardado = Vuelto::paraGuardar('cash', null, 47300, $this->company->id);

        $this->assertNull($guardado['cash_received']);
    }

    // ----------------------------------------------- de punta a punta

    /**
     * El cobro queda guardado con su entrega y su vuelto.
     *
     * Y `amount` no cambia: el vuelto no es una salida de caja, es la parte
     * del billete que nunca fue del negocio.
     */
    public function test_el_cobro_guarda_el_vuelto_sin_tocar_el_monto(): void
    {
        $factura = $this->facturaContabilizada(47300);

        $pago = app(SaleInvoiceEngine::class)->addPayment($factura->fresh(), [
            'date' => now()->toDateString(),
            'amount' => 47300,
            'cash_received' => 50000,
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->registrarLimpieza($pago->id);

        $fresco = $pago->fresh();

        $this->assertSame(47300.0, (float) $fresco->amount,
            'Lo que entra al cajón es lo cobrado, no el billete completo.');
        $this->assertSame(50000.0, (float) $fresco->cash_received);
        $this->assertSame(2700.0, (float) $fresco->change_given);
        $this->assertEqualsWithDelta(
            (float) $fresco->amount,
            (float) $fresco->cash_received - (float) $fresco->change_given,
            0.01,
            'Recibido menos vuelto tiene que dar el monto cobrado.',
        );
    }

    /** El tiquete lo imprime. */
    public function test_el_tiquete_imprime_recibido_y_cambio(): void
    {
        $vista = file_get_contents(resource_path('views/pos/print-ticket.blade.php'));

        $this->assertStringContainsString('Recibido', $vista);
        $this->assertStringContainsString('Cambio', $vista);
        $this->assertStringContainsString('cash_received', $vista,
            'Sin tiquete, un reclamo de «me devolvió mal» se resuelve de memoria contra memoria.');
    }

    /**
     * Los tres POS pasan el dato al motor.
     *
     * Si uno se queda sin pasarlo, ese POS guarda el cobro sin vuelto y el
     * tiquete de ese mostrador sale distinto al de al lado.
     */
    public function test_los_tres_pos_pasan_el_recibido(): void
    {
        $fuentes = [
            'retail' => app_path('Filament/App/Pages/PosTerminal.php'),
            'restaurante' => app_path('Services/Restaurant/RestaurantOrderEngine.php'),
            'parqueadero' => app_path('Services/Parking/ParkingBillingEngine.php'),
        ];

        foreach ($fuentes as $nombre => $ruta) {
            $this->assertStringContainsString("'cash_received'", file_get_contents($ruta),
                "El POS de {$nombre} no le pasa al motor con cuánto pagó el cliente.");
        }
    }

    // --------------------------------------------------------- auxiliares

    private function registrarLimpieza(int $pagoId): void
    {
        $this->limpiar[] = function () use ($pagoId) {
            $asiento = DB::table('payments')->where('id', $pagoId)->value('journal_entry_id');
            DB::table('payments')->where('id', $pagoId)->delete();
            if ($asiento) {
                DB::table('journal_entry_lines')->where('journal_entry_id', $asiento)->delete();
                DB::table('journal_entries')->where('id', $asiento)->delete();
            }
        };
    }

    private function facturaContabilizada(float $total): SaleInvoice
    {
        $this->sede ??= Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $tercero = ThirdParty::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHour(),
            'opening_amount' => 0,
        ]);
        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $tercero->id,
            'cash_register_session_id' => $turno->id,
            'prefix' => 'ZZVU',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
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
