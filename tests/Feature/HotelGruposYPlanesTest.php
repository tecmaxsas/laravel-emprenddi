<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Hotel\MealPlan;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationGroup;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\Location;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Hotel\HotelBillingEngine;
use App\Services\Hotel\HotelConfirmacion;
use App\Services\Hotel\HotelFolio;
use App\Services\Hotel\HotelGroupEngine;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelStayEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use App\Support\WhatsappNumero;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * Grupos, planes de alimentación y la confirmación al huésped.
 *
 * Las tres cosas que dejaban fuera a clientes enteros:
 *
 *  · **Grupos.** Diez habitaciones eran diez reservas sueltas y ninguna forma
 *    de cobrarlas juntas. Agencias y corporativos son un pedazo grande del
 *    ingreso de un hotel urbano.
 *  · **Planes de alimentación.** Vender «doble con desayuno» obligaba a
 *    duplicar el tipo de habitación entero.
 *  · **Confirmación.** El código de la reserva se dictaba por teléfono.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class HotelGruposYPlanesTest extends TestCase
{
    use MontaUnHotel;

    private User $usuario;

    private ThirdParty $empresaCliente;

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
        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 4);
        $this->abreUnTurnoDeCaja($this->empresa, $this->sede, (int) $this->usuario->id, $this->limpiar);
        $this->empresaCliente = $this->clienteEmpresa();
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Planes de alimentación */
    /* ------------------------------------------------------------------ */

    /** Un plan que se cobra entra al folio una vez por noche, por persona. */
    public function test_el_plan_se_cobra_por_noche_y_por_persona(): void
    {
        $plan = $this->plan('CP', 'Con desayuno ZZ', porAdulto: 15000, porNino: 8000);

        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->addDays(2)->toDateString(),
            'adults' => 2,
            'children' => 1,
            'meal_plan_id' => $plan->id,
        ]);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $cargos = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->where('kind', StayCharge::KIND_MEAL)
            ->get();

        $this->assertCount(1, $cargos, 'Una sola noche cargada: el plan va pegado a ella.');

        // 2 adultos × 15.000 + 1 niño × 8.000
        $this->assertEqualsWithDelta(38000, (float) $cargos->first()->total, 0.01);
    }

    /** El pase nocturno no cobra dos desayunos de la misma noche. */
    public function test_el_plan_no_se_duplica(): void
    {
        $plan = $this->plan('CP', 'Con desayuno ZZ', porAdulto: 15000);

        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->addDays(3)->toDateString(),
            'adults' => 1,
            'meal_plan_id' => $plan->id,
        ]);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $folio = app(HotelFolio::class);
        $folio->cargarNochesPendientes($estadia);
        $folio->cargarNochesPendientes($estadia);

        $this->assertSame(
            1,
            StayCharge::query()->where('stay_id', $estadia->id)
                ->where('kind', StayCharge::KIND_MEAL)->count(),
        );
    }

    /** Un plan incluido en la tarifa no cobra nada: solo informa. */
    public function test_un_plan_incluido_no_cobra(): void
    {
        $plan = $this->plan('AI', 'Todo incluido ZZ', porAdulto: 0, incluido: true);

        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->addDay()->toDateString(),
            'meal_plan_id' => $plan->id,
        ]);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $this->assertSame(
            0,
            StayCharge::query()->where('stay_id', $estadia->id)
                ->where('kind', StayCharge::KIND_MEAL)->count(),
        );

        $this->assertSame((int) $plan->id, (int) $estadia->meal_plan_id,
            'Pero sí queda registrado: el huésped necesita saber que puede bajar a comer.');
    }

    /**
     * El que se va antes no paga el desayuno que no se comió.
     *
     * Es el mismo caso que la noche que no durmió, y falla igual de callado si
     * el plan no se liquida con ella.
     */
    public function test_el_plan_se_va_con_la_noche_que_no_se_durmio(): void
    {
        $plan = $this->plan('CP', 'Con desayuno ZZ', porAdulto: 20000);
        $entrada = now()->subDays(2);

        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_at' => $entrada->toDateTimeString(),
            'expected_check_out' => $entrada->copy()->addDays(5)->toDateString(),
            'adults' => 1,
            'meal_plan_id' => $plan->id,
        ]);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        app(HotelFolio::class)->cargarNochesPendientes($estadia);

        $antes = StayCharge::query()->where('stay_id', $estadia->id)
            ->where('kind', StayCharge::KIND_MEAL)->count();

        $this->assertSame(3, $antes, 'Tres noches cargadas hasta hoy.');

        // Se va hoy a las 10 de la mañana: durmió dos noches.
        app(HotelStayEngine::class)->liquidar($estadia->fresh(), now()->copy()->setTime(10, 0));

        $this->assertSame(
            2,
            StayCharge::query()->where('stay_id', $estadia->id)
                ->where('kind', StayCharge::KIND_MEAL)->count(),
            'El desayuno de la noche que no durmió tiene que salir del folio.',
        );
    }

    /** El plan pactado en la reserva se hereda al alojar. */
    public function test_el_checkin_hereda_el_plan_de_la_reserva(): void
    {
        $plan = $this->plan('MAP', 'Media pensión ZZ', porAdulto: 30000);

        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'guest_name' => 'Con plan ZZ',
            'meal_plan_id' => $plan->id,
        ]);
        $this->limpiar[] = fn () => DB::table('hotel_reservations')->where('id', $reserva->id)->delete();

        $estadia = app(HotelStayEngine::class)->checkInDeReserva($reserva);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $this->assertSame((int) $plan->id, (int) $estadia->meal_plan_id,
            'Si reservó con media pensión, baja a almorzar esperándola.');
    }

    /* ------------------------------------------------------------------ */
    /*  Confirmación */
    /* ------------------------------------------------------------------ */

    /** El mensaje lleva lo que el huésped necesita para no tener un roce. */
    public function test_la_confirmacion_dice_codigo_fechas_y_horas(): void
    {
        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => now()->addDays(5)->toDateString(),
            'check_out_date' => now()->addDays(7)->toDateString(),
            'guest_name' => 'Marcela Ocampo ZZ',
            'guest_phone' => '3105551234',
        ]);
        $this->limpiar[] = fn () => DB::table('hotel_reservations')->where('id', $reserva->id)->delete();

        $mensaje = app(HotelConfirmacion::class)->mensaje($reserva->fresh());

        $this->assertStringContainsString($reserva->code, $mensaje, 'El código es lo que va a decir al llamar.');
        $this->assertStringContainsString('Marcela', $mensaje);
        $this->assertStringContainsString('15:00', $mensaje, 'La hora de entrada evita el roce de las 10 a.m.');
        $this->assertStringContainsString('12:00', $mensaje);
        $this->assertStringContainsString('Noches: 2', $mensaje,
            '«Del 5 al 7» es ambiguo; decir las noches al lado lo resuelve.');
    }

    /** Y el enlace de WhatsApp sale con el indicativo puesto. */
    public function test_el_enlace_de_whatsapp_normaliza_el_numero(): void
    {
        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => now()->addDays(5)->toDateString(),
            'check_out_date' => now()->addDays(6)->toDateString(),
            'guest_name' => 'Celular ZZ',
            'guest_phone' => '310 555 1234',
        ]);
        $this->limpiar[] = fn () => DB::table('hotel_reservations')->where('id', $reserva->id)->delete();

        $enlace = app(HotelConfirmacion::class)->enlaceWhatsapp($reserva->fresh());

        $this->assertNotNull($enlace);
        $this->assertStringStartsWith('https://wa.me/573105551234?text=', $enlace);
    }

    /** Un teléfono incompleto no genera enlace: abriría un chat con nadie. */
    public function test_sin_telefono_util_no_hay_enlace(): void
    {
        $this->assertNull(WhatsappNumero::para('123'));
        $this->assertNull(WhatsappNumero::enlace(null, 'hola'));
        $this->assertSame('573105551234', WhatsappNumero::para('310-555-1234'));
    }

    /* ------------------------------------------------------------------ */
    /*  Grupos */
    /* ------------------------------------------------------------------ */

    /** Crear un grupo aparta una reserva por habitación. */
    public function test_el_grupo_crea_una_reserva_por_habitacion(): void
    {
        $resultado = $this->grupo(cantidad: 3);

        $this->assertSame(3, $resultado['creadas']);
        $this->assertSame([], $resultado['faltaron']);

        $grupo = $resultado['grupo'];

        $this->assertNotNull($grupo->code);
        $this->assertSame('G-'.str_pad((string) $grupo->id, 6, '0', STR_PAD_LEFT), $grupo->code);
        $this->assertSame(3, $grupo->reservations()->count());

        // Cada una con su habitación: el bloque no reserva tres veces la misma.
        $habitaciones = $grupo->reservations()->pluck('room_id')->unique();
        $this->assertCount(3, $habitaciones);
    }

    /**
     * Si no alcanzan, se crean las que haya y se dice cuántas faltaron.
     *
     * Reventar sin crear nada dejaría al recepcionista con el cliente al
     * teléfono y las manos vacías; prometer diez que no existen es peor.
     */
    public function test_cuando_no_alcanzan_crea_las_que_hay_y_avisa(): void
    {
        // El hotel de prueba tiene 4 habitaciones y se piden 6.
        $resultado = $this->grupo(cantidad: 6);

        $this->assertSame(4, $resultado['creadas']);
        $this->assertNotEmpty($resultado['faltaron']);
        $this->assertStringContainsString('faltaron 2', $resultado['faltaron'][0]);
    }

    /** El grupo respeta el sobrecupo igual que cualquier reserva. */
    public function test_el_grupo_no_pisa_una_reserva_existente(): void
    {
        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => now()->addDays(10)->toDateString(),
            'check_out_date' => now()->addDays(12)->toDateString(),
            'guest_name' => 'Ya estaba ZZ',
        ]);
        $this->limpiar[] = fn () => DB::table('hotel_reservations')->where('id', $reserva->id)->delete();

        $resultado = $this->grupo(cantidad: 4, enDias: 10, noches: 2);

        $this->assertSame(3, $resultado['creadas'], 'Solo quedaban tres: la otra ya estaba apartada.');

        $ocupadas = $resultado['grupo']->reservations()->pluck('room_id')->all();
        $this->assertNotContains((int) $reserva->room_id, array_map('intval', $ocupadas));
    }

    /** Cancelar el bloque cancela todas sus reservas vivas. */
    public function test_cancelar_el_grupo_cancela_sus_reservas(): void
    {
        $grupo = $this->grupo(cantidad: 3)['grupo'];

        $resultado = app(HotelGroupEngine::class)->cancelar($grupo, 'Se cayó el evento ZZ');

        $this->assertSame(3, $resultado['canceladas']);
        $this->assertSame(ReservationGroup::STATUS_CANCELLED, $grupo->fresh()->status);

        $vivas = $grupo->reservations()->queBloquean()->count();
        $this->assertSame(0, $vivas, 'Ninguna puede seguir comprometiendo habitaciones.');
    }

    /**
     * Facturar el grupo entero en un solo documento.
     *
     * Es lo que pedía el caso: una empresa no paga diez facturas.
     */
    public function test_el_grupo_se_factura_en_un_solo_documento(): void
    {
        $grupo = $this->grupo(cantidad: 2, enDias: 0)['grupo'];

        // Entran las dos.
        $estadias = [];

        foreach ($grupo->reservations as $reserva) {
            $estadias[] = app(HotelStayEngine::class)->checkInDeReserva($reserva);
        }

        foreach ($estadias as $estadia) {
            $this->limpiar[] = fn () => $this->borraEstadia($estadia);
        }

        // Una consume algo, para que las líneas no sean solo noches.
        app(HotelFolio::class)->agregarConsumo($estadias[0]->fresh(), [
            'description' => 'Lavandería ZZ',
            'quantity' => 1,
            'unit_price' => 20000,
        ]);

        $factura = app(HotelBillingEngine::class)->issueForGroup($grupo->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'third_party_id' => $this->empresaCliente->id,
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        // Una sola factura, con líneas de las dos habitaciones.
        $this->assertGreaterThanOrEqual(3, $factura->lines()->count());

        $descripciones = $factura->lines()->pluck('description')->implode(' | ');
        $this->assertStringContainsString('Hab. ', $descripciones,
            'Cada línea tiene que decir de qué habitación es.');
        $this->assertStringContainsString('Lavandería ZZ', $descripciones);

        // Las dos estadías quedan cerradas y apuntando a la MISMA factura.
        foreach ($estadias as $estadia) {
            $cerrada = $estadia->fresh();
            $this->assertSame(Stay::STATUS_CLOSED, $cerrada->status);
            $this->assertSame((int) $factura->id, (int) $cerrada->sale_invoice_id);
        }

        $this->assertSame(ReservationGroup::STATUS_CLOSED, $grupo->fresh()->status);
        $this->assertSame((int) $factura->id, (int) $grupo->fresh()->sale_invoice_id);
    }

    /** Y se puede facturar a crédito, que es lo normal con una empresa. */
    public function test_el_grupo_se_puede_facturar_a_credito(): void
    {
        $this->empresaCliente->update(['payment_terms_days' => 30]);

        $grupo = $this->grupo(cantidad: 2, enDias: 0)['grupo'];

        foreach ($grupo->reservations as $reserva) {
            $estadia = app(HotelStayEngine::class)->checkInDeReserva($reserva);
            $this->limpiar[] = fn () => $this->borraEstadia($estadia);
        }

        $factura = app(HotelBillingEngine::class)->issueForGroup($grupo->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'third_party_id' => $this->empresaCliente->id,
            'a_credito' => true,
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->assertEqualsWithDelta(0, (float) $factura->paid_amount, 0.01);
        $this->assertSame(30, (int) $factura->payment_terms_days);
        $this->assertNotNull($factura->due_date);
    }

    /** Un grupo sin nadie adentro no se factura. */
    public function test_no_se_factura_un_grupo_sin_estadias(): void
    {
        $grupo = $this->grupo(cantidad: 2)['grupo'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene estadías abiertas/i');

        app(HotelBillingEngine::class)->issueForGroup($grupo, [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);
    }

    // --------------------------------------------------------- auxiliares

    /** @return array{grupo: ReservationGroup, creadas: int, faltaron: list<string>} */
    private function grupo(int $cantidad, int $enDias = 20, int $noches = 2): array
    {
        $entrada = now()->addDays($enDias)->startOfDay();

        $resultado = app(HotelGroupEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'name' => 'Grupo ZZ '.random_int(100, 999),
            'check_in_date' => $entrada->toDateString(),
            'check_out_date' => $entrada->copy()->addDays($noches)->toDateString(),
            'third_party_id' => $this->empresaCliente->id,
            'habitaciones' => [
                ['room_type_id' => $this->tipoDoble->id, 'cantidad' => $cantidad, 'adults' => 2],
            ],
        ]);

        $grupo = $resultado['grupo'];

        $this->limpiar[] = function () use ($grupo) {
            DB::table('hotel_reservations')->where('group_id', $grupo->id)->delete();
            DB::table('hotel_reservation_groups')->where('id', $grupo->id)->delete();
        };

        return $resultado;
    }

    private function plan(
        string $codigo,
        string $nombre,
        float $porAdulto,
        float $porNino = 0,
        bool $incluido = false,
    ): MealPlan {
        $plan = MealPlan::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'hotel_id' => $this->hotel->id,
            'code' => $codigo.random_int(10, 99),
            'name' => $nombre,
            'included_in_rate' => $incluido,
            'price_per_adult' => $porAdulto,
            'price_per_child' => $porNino,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('hotel_meal_plans')->where('id', $plan->id)->delete();

        return $plan;
    }

    private function clienteEmpresa(): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZG'.random_int(10000, 99999),
            'name' => 'Agencia ZZ Grupos',
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
        DB::table('hotel_reservation_groups')->where('sale_invoice_id', $facturaId)
            ->update(['sale_invoice_id' => null]);

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

        DB::table('payments')->whereIn('id', $pagos->pluck('id'))->delete();
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
        DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        DB::table('sale_invoice_lines')->where('sale_invoice_id', $facturaId)->delete();
        DB::table('sale_invoices')->where('id', $facturaId)->delete();
    }

    private function daleResolucionPosALaSede(): void
    {
        $resolucion = Resolution::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->empresa->id, 'prefix' => 'ZZG'],
            [
                'kind' => Resolution::KIND_POS,
                'document_type_id' => 1,
                'document_type_name' => Resolution::DOCUMENT_TYPES[1],
                'resolution_number' => 'ZZ-GRUPO',
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
