<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerAdvance;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Hotel\HotelBillingEngine;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelStayEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * El depósito de la reserva y el check-out a crédito.
 *
 * Son dos agujeros de la primera entrega, y los dos del mismo tipo: la
 * pantalla daba a entender una cosa y el sistema hacía otra.
 *
 *  · **El depósito** se escribía, se mostraba y nadie lo leía. No se cruzaba
 *    con ningún anticipo ni se descontaba al facturar. Un hotel que ve el
 *    campo asume que sí —es lo razonable— y cuando descubre que no, ya cobró
 *    el total a alguien que había abonado la mitad.
 *  · **El crédito** no existía: la pantalla siempre cobraba todo. La empresa
 *    que manda empleados todo el mes y paga a treinta días —cliente típico de
 *    hotel urbano— no se podía atender.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class HotelDepositoYCreditoTest extends TestCase
{
    use MontaUnHotel;

    private User $usuario;

    private ThirdParty $cliente;

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
            ->where('company_id', $this->empresa->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->enciendeElModuloHotel($this->empresa, $this->limpiar);
        $this->daleResolucionPosALaSede();
        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 2);
        $this->abreUnTurnoDeCaja($this->empresa, $this->sede, (int) $this->usuario->id, $this->limpiar);
        $this->cliente = $this->clienteConNombre();
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Depósito */
    /* ------------------------------------------------------------------ */

    /**
     * Lo pactado y lo recibido son dos cosas distintas.
     *
     * Y la que hay que perseguir es justo la reserva que tiene lo uno y no lo
     * otro: depósito acordado y sin pagar.
     */
    public function test_pactar_un_deposito_no_es_recibirlo(): void
    {
        $reserva = $this->reserva(deposito: 200000);

        $this->assertEqualsWithDelta(200000, (float) $reserva->deposit_amount, 0.01);
        $this->assertEqualsWithDelta(0, $reserva->depositoRecibido(), 0.01, 'Todavía no ha entrado nada.');
        $this->assertTrue($reserva->tieneDepositoPendiente());
    }

    /** Recibirlo crea plata de verdad, en el turno de caja. */
    public function test_recibir_el_deposito_entra_a_la_caja(): void
    {
        $reserva = $this->reserva(deposito: 200000);

        $anticipo = app(HotelBillingEngine::class)->recibirDepositoDeReserva($reserva, [
            'amount' => 200000,
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'third_party_id' => $this->cliente->id,
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        $reserva = $reserva->fresh();

        $this->assertEqualsWithDelta(200000, (float) $anticipo->amount, 0.01);
        $this->assertSame((int) $anticipo->id, (int) $reserva->deposit_advance_id);
        $this->assertEqualsWithDelta(200000, $reserva->depositoRecibido(), 0.01);
        $this->assertFalse($reserva->tieneDepositoPendiente());

        $this->assertNotNull(
            $anticipo->cash_register_session_id,
            'La plata tiene que quedar en el turno del día en que entró, no en el del check-out.',
        );
    }

    /** A Consumidor Final no se le puede guardar un saldo a favor. */
    public function test_el_deposito_exige_cliente_con_nombre(): void
    {
        $reserva = $this->reserva(deposito: 100000, conCliente: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/a nombre de quién/i');

        app(HotelBillingEngine::class)->recibirDepositoDeReserva($reserva, ['amount' => 100000]);
    }

    /** No se recibe dos veces el mismo depósito. */
    public function test_no_se_recibe_el_deposito_dos_veces(): void
    {
        $reserva = $this->reserva(deposito: 150000);

        $anticipo = app(HotelBillingEngine::class)->recibirDepositoDeReserva($reserva, [
            'amount' => 150000,
            'third_party_id' => $this->cliente->id,
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya tiene un depósito/i');

        app(HotelBillingEngine::class)->recibirDepositoDeReserva($reserva->fresh(), ['amount' => 150000]);
    }

    /**
     * Y en el check-out se cruza solo: el huésped paga la diferencia.
     *
     * Esta es la prueba que de verdad cierra el agujero. Lo demás es
     * contabilidad; esto es lo que el huésped siente en el mostrador.
     */
    public function test_el_deposito_se_cruza_al_facturar_el_checkout(): void
    {
        $reserva = $this->reserva(deposito: 60000);

        $anticipo = app(HotelBillingEngine::class)->recibirDepositoDeReserva($reserva, [
            'amount' => 60000,
            'third_party_id' => $this->cliente->id,
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        $estadia = app(HotelStayEngine::class)->checkInDeReserva($reserva->fresh());
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $total = (float) $estadia->fresh()->total;
        $this->assertGreaterThan(60000, $total, 'La estadía tiene que costar más que el depósito.');

        // Se cobra solo la diferencia.
        $factura = app(HotelBillingEngine::class)->issueForStay($estadia->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'paid_amount' => $total - 60000,
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->assertEqualsWithDelta(
            $total,
            (float) $factura->paid_amount,
            1.0,
            'El depósito más la diferencia tienen que cubrir la factura completa.',
        );

        $this->assertEqualsWithDelta(
            60000,
            (float) $anticipo->fresh()->applied_amount,
            0.01,
            'El anticipo tiene que quedar aplicado.',
        );
    }

    /**
     * Al cancelar, el depósito no desaparece en silencio.
     *
     * El sistema no decide si se devuelve o se retiene —eso es de cada caso—
     * pero sí tiene que decir que esa plata está ahí, a nombre de alguien.
     */
    public function test_al_cancelar_avisa_del_deposito(): void
    {
        $reserva = $this->reserva(deposito: 80000);

        $anticipo = app(HotelBillingEngine::class)->recibirDepositoDeReserva($reserva, [
            'amount' => 80000,
            'third_party_id' => $this->cliente->id,
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);

        $this->limpiar[] = fn () => DB::table('customer_advances')->where('id', $anticipo->id)->delete();

        $motor = app(HotelReservationEngine::class);
        $aviso = $motor->avisoDelDeposito($reserva->fresh());

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('80.000', $aviso);

        $motor->cancelar($reserva->fresh(), 'El cliente cambió de planes ZZ');

        // La plata sigue a nombre del cliente: no se evapora al cancelar.
        $this->assertEqualsWithDelta(80000, (float) $anticipo->fresh()->amount, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /*  Crédito */
    /* ------------------------------------------------------------------ */

    /**
     * El check-out a crédito factura y deja la cartera abierta.
     */
    public function test_el_checkout_a_credito_deja_la_factura_pendiente(): void
    {
        $this->cliente->update(['payment_terms_days' => 30]);

        $estadia = $this->alojar();
        $estadia->update(['third_party_id' => $this->cliente->id]);

        $factura = app(HotelBillingEngine::class)->issueForStay($estadia->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'a_credito' => true,
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->assertEqualsWithDelta(0, (float) $factura->paid_amount, 0.01, 'No entró plata.');
        $this->assertGreaterThan(0, (float) $factura->total);
        $this->assertSame(SaleInvoice::PAYMENT_PENDIENTE, $factura->payment_status);

        $this->assertSame(30, (int) $factura->payment_terms_days);
        $this->assertNotNull($factura->due_date, 'Sin vencimiento la cartera no sirve para perseguir a nadie.');
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $factura->due_date->toDateString(),
        );

        // Y la estadía queda cerrada igual: el huésped se fue.
        $cerrada = $estadia->fresh();
        $this->assertSame(Stay::STATUS_CLOSED, $cerrada->status);
        $this->assertSame(Room::STATUS_CLEANING, $cerrada->room->status);
    }

    /**
     * A Consumidor Final no se le fía.
     *
     * La cartera se cobra por tercero: una factura a crédito a nombre de
     * «Consumidor Final» es plata que nadie va a poder cobrar porque no se
     * sabe a quién llamar.
     */
    public function test_no_se_fia_a_consumidor_final(): void
    {
        $estadia = $this->alojar();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Consumidor Final/i');

        app(HotelBillingEngine::class)->issueForStay($estadia->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'a_credito' => true,
        ]);
    }

    /** De contado sigue funcionando igual que antes. */
    public function test_de_contado_sigue_cobrando_todo(): void
    {
        $estadia = $this->alojar();

        $factura = app(HotelBillingEngine::class)->issueForStay($estadia->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->assertEqualsWithDelta((float) $factura->total, (float) $factura->paid_amount, 0.01);
        $this->assertNull($factura->due_date, 'Una venta de contado no vence.');
    }

    // --------------------------------------------------------- auxiliares

    private function reserva(float $deposito, bool $conCliente = true): Reservation
    {
        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'guest_name' => 'Depósito ZZ',
            'third_party_id' => $conCliente ? $this->cliente->id : null,
            'deposit_amount' => $deposito,
        ]);

        $this->limpiar[] = function () use ($reserva) {
            DB::table('hotel_reservations')->where('id', $reserva->id)->delete();
        };

        return $reserva;
    }

    private function alojar(): Stay
    {
        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 2)->id,
            'expected_check_out' => now()->addDay()->toDateString(),
        ]);

        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        return $estadia;
    }

    private function clienteConNombre(): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'Empresa ZZ Convenio',
            'is_customer' => true,
            'address' => 'Sin dirección',
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($cliente) {
            DB::table('customer_advances')->where('third_party_id', $cliente->id)->delete();
            DB::table('third_parties')->where('id', $cliente->id)->delete();
        };

        return $cliente;
    }

    private function borraEstadia(Stay $estadia): void
    {
        DB::table('hotel_reservations')->where('stay_id', $estadia->id)->update(['stay_id' => null]);
        DB::table('hotel_stay_guests')->where('stay_id', $estadia->id)->delete();
        DB::table('hotel_stay_charges')->where('stay_id', $estadia->id)->delete();
        DB::table('hotel_stays')->where('id', $estadia->id)->delete();
        Room::withoutGlobalScopes()->where('id', $estadia->room_id)
            ->update(['status' => Room::STATUS_AVAILABLE]);
    }

    private function borraFactura(int $facturaId): void
    {
        DB::table('hotel_stays')->where('sale_invoice_id', $facturaId)->update(['sale_invoice_id' => null]);

        $pagos = DB::table('payments')
            ->where('paymentable_type', 'App\\Models\\SaleInvoice')
            ->where('paymentable_id', $facturaId)
            ->get(['id', 'journal_entry_id']);

        $asientos = $pagos->pluck('journal_entry_id')->filter()->values();

        if ($deLaFactura = DB::table('sale_invoices')->where('id', $facturaId)->value('journal_entry_id')) {
            $asientos->push($deLaFactura);
        }

        if ($numero = DB::table('sale_invoices')->where('id', $facturaId)->value('number')) {
            DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)
                ->where('type', 'cogs')
                ->where('reference', 'like', '%'.$numero.'%')
                ->pluck('id')
                ->each(fn ($id) => $asientos->push($id));
        }

        // Solo los anticipos de ESTE cliente de prueba. Un update sin filtro
        // aqui reventaria la cartera de todas las empresas de la base.
        DB::table('customer_advances')
            ->where('third_party_id', $this->cliente->id)
            ->update(['applied_amount' => 0]);

        DB::table('payments')->whereIn('id', $pagos->pluck('id'))->delete();
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
        DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        DB::table('sale_invoice_lines')->where('sale_invoice_id', $facturaId)->delete();
        DB::table('sale_invoices')->where('id', $facturaId)->delete();
    }

    private function daleResolucionPosALaSede(): void
    {
        $resolucion = Resolution::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->empresa->id, 'prefix' => 'ZZD'],
            [
                'kind' => Resolution::KIND_POS,
                'document_type_id' => 1,
                'document_type_name' => Resolution::DOCUMENT_TYPES[1],
                'resolution_number' => 'ZZ-DEP',
                'range_from' => 1,
                'range_to' => 999999,
                'active' => true,
            ],
        );

        $vinculo = LocationResolution::withoutGlobalScopes()->firstOrCreate(
            ['location_id' => $this->sede->id, 'dian_resolution_id' => $resolucion->id],
            ['current_consecutive' => 0, 'active' => true],
        );

        $this->limpiar[] = fn () => LocationResolution::withoutGlobalScopes()->whereKey($vinculo->id)->forceDelete();
        $this->limpiar[] = fn () => Resolution::withoutGlobalScopes()->whereKey($resolucion->id)->forceDelete();
    }
}
