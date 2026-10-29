<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Location;
use App\Models\User;
use App\Services\Hotel\HotelAvailability;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelStayEngine;
use App\Support\CurrentCompany;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * El sobrecupo: vender dos veces la misma noche.
 *
 * Es el error que un hotel no puede permitirse, porque no se arregla con un
 * parche: hay dos familias en el lobby y una sola habitación. Y es fácil de
 * cometer, porque la pregunta «¿está libre?» se hace por cuatro caminos
 * distintos —crear una reserva, anclarla, hacer check-in sin reserva y pintar
 * el mapa— y basta que uno de los cuatro la responda a su manera.
 *
 * Por eso la regla vive en un solo sitio, HotelAvailability, y estas pruebas
 * entran por los cuatro caminos para comprobar que todos pasan por ahí.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class HotelDisponibilidadTest extends TestCase
{
    use MontaUnHotel;

    private User $usuario;

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
        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 2);
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /**
     * La decisión de la que depende todo: `check_out_date` es EXCLUSIVA.
     *
     * Quien sale el 10 deja la habitación libre para quien llega el 10. Con el
     * convenio al revés, el hotel pierde una noche vendible por cada reserva,
     * que en temporada alta es la mitad del inventario.
     */
    public function test_la_salida_de_uno_es_la_entrada_del_siguiente(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $this->reserva($habitacion, '2027-03-08', '2027-03-10');

        $disponibilidad = app(HotelAvailability::class);

        $this->assertTrue(
            $disponibilidad->estaLibre($habitacion, '2027-03-10', '2027-03-12'),
            'La habitación debería quedar libre el mismo día en que el otro huésped se va.',
        );

        $this->assertFalse(
            $disponibilidad->estaLibre($habitacion, '2027-03-09', '2027-03-11'),
            'Esas fechas se solapan con la noche del 9.',
        );
    }

    /** Del 8 al 10 son dos noches, no tres: la de salida no se duerme. */
    public function test_las_noches_del_rango(): void
    {
        $disponibilidad = app(HotelAvailability::class);

        $this->assertSame(2, $disponibilidad->noches('2027-03-08', '2027-03-10'));
        $this->assertSame(1, $disponibilidad->noches('2027-03-08', '2027-03-09'));
    }

    /** Una salida anterior a la entrada no es un rango. */
    public function test_el_rango_al_reves_se_rechaza(): void
    {
        $this->expectException(\RuntimeException::class);

        app(HotelAvailability::class)->noches('2027-03-10', '2027-03-08');
    }

    /**
     * Camino 1: crear una reserva encima de otra.
     */
    public function test_no_se_puede_reservar_sobre_una_reserva(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $this->reserva($habitacion, '2027-04-05', '2027-04-08');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no está disponible/i');

        $this->reserva($habitacion, '2027-04-06', '2027-04-09');
    }

    /**
     * El motivo del choque importa.
     *
     * «No está disponible» obliga al recepcionista a revisar a mano con el
     * cliente al frente; «la tiene la reserva R-0012 de Pedro Gómez» se
     * resuelve en el mostrador.
     */
    public function test_el_error_dice_cual_reserva_estorba(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $reserva = $this->reserva($habitacion, '2027-05-01', '2027-05-04', 'Pedro Gómez ZZ');

        $conflictos = app(HotelAvailability::class)
            ->conflictosDe($habitacion, '2027-05-02', '2027-05-05');

        $this->assertCount(1, $conflictos);
        $this->assertStringContainsString($reserva->code, $conflictos[0]);
        $this->assertStringContainsString('Pedro Gómez ZZ', $conflictos[0]);
    }

    /**
     * Camino 2: anclar una reserva a una habitación ya comprometida.
     */
    public function test_no_se_ancla_una_reserva_a_una_habitacion_comprometida(): void
    {
        $uno = $this->habitacion($this->hotel, 1);
        $dos = $this->habitacion($this->hotel, 2);

        $this->reserva($uno, '2027-06-10', '2027-06-13');
        $otra = $this->reserva($dos, '2027-06-11', '2027-06-14');

        $this->expectException(\RuntimeException::class);

        app(HotelReservationEngine::class)->asignarHabitacion($otra, (int) $uno->id);
    }

    /**
     * Y la reserva no se choca contra sí misma: reasignarla a la habitación
     * que ya tenía es legítimo.
     */
    public function test_una_reserva_no_choca_consigo_misma(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $reserva = $this->reserva($habitacion, '2027-07-01', '2027-07-03');

        $reasignada = app(HotelReservationEngine::class)
            ->asignarHabitacion($reserva, (int) $habitacion->id);

        $this->assertSame((int) $habitacion->id, (int) $reasignada->room_id);
    }

    /**
     * Camino 3: check-in de mostrador encima de una reserva confirmada.
     */
    public function test_el_checkin_de_mostrador_respeta_las_reservas(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $this->reserva($habitacion, now()->toDateString(), now()->addDays(3)->toDateString());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no está disponible/i');

        app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'expected_check_out' => now()->addDay()->toDateString(),
        ]);
    }

    /**
     * Una reserva cancelada libera lo que tenía apartado.
     *
     * Si siguiera bloqueando, el hotel se quedaría con habitaciones
     * invendibles por reservas que nadie va a usar.
     */
    public function test_una_reserva_cancelada_libera_la_habitacion(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $reserva = $this->reserva($habitacion, '2027-08-01', '2027-08-05');

        app(HotelReservationEngine::class)->cancelar($reserva, 'El cliente avisó ZZ');

        $this->assertTrue(
            app(HotelAvailability::class)->estaLibre($habitacion, '2027-08-01', '2027-08-05'),
            'Una reserva cancelada no debería seguir comprometiendo la habitación.',
        );
    }

    /** Cancelar sin motivo no se permite: es lo que sustenta el depósito. */
    public function test_cancelar_exige_motivo(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $reserva = $this->reserva($habitacion, '2027-08-10', '2027-08-12');

        $this->expectException(\RuntimeException::class);

        app(HotelReservationEngine::class)->cancelar($reserva, '   ');
    }

    /**
     * Mantenimiento pesa más que cualquier fecha.
     */
    public function test_una_habitacion_en_mantenimiento_no_se_vende_nunca(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $habitacion->update(['status' => Room::STATUS_MAINTENANCE]);

        $this->assertFalse(
            app(HotelAvailability::class)->estaLibre($habitacion, '2028-01-01', '2028-01-03'),
            'En mantenimiento no se vende, ni para dentro de un año.',
        );
    }

    /**
     * Reservar por tipo también comprueba que quede alguna.
     *
     * Prometer «una doble» cuando las dos están apartadas es el mismo
     * sobrecupo, solo que descubierto más tarde.
     */
    public function test_reservar_por_tipo_se_niega_cuando_no_quedan(): void
    {
        $motor = app(HotelReservationEngine::class);

        $this->reserva($this->habitacion($this->hotel, 1), '2027-09-01', '2027-09-03');
        $this->reserva($this->habitacion($this->hotel, 2), '2027-09-01', '2027-09-03');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No quedan habitaciones/i');

        $motor->crear([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->tipoDoble->id,
            'check_in_date' => '2027-09-01',
            'check_out_date' => '2027-09-03',
            'guest_name' => 'Tercero ZZ',
        ]);
    }

    /** La capacidad es un tope físico, no una sugerencia. */
    public function test_no_se_reserva_por_encima_de_la_capacidad(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admite/i');

        app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->tipoDoble->id,
            'check_in_date' => '2027-10-01',
            'check_out_date' => '2027-10-02',
            'guest_name' => 'Familia grande ZZ',
            'adults' => 5,
        ]);
    }

    /** El código de la reserva se deriva del id: nunca se repite. */
    public function test_la_reserva_nace_con_codigo(): void
    {
        $reserva = $this->reserva($this->habitacion($this->hotel, 1), '2027-11-01', '2027-11-03');

        $this->assertNotNull($reserva->code);
        $this->assertSame('R-'.str_pad((string) $reserva->id, 6, '0', STR_PAD_LEFT), $reserva->code);
    }

    /** `habitacionesLibres` no devuelve las comprometidas. */
    public function test_las_libres_excluyen_las_comprometidas(): void
    {
        $uno = $this->habitacion($this->hotel, 1);

        $this->reserva($uno, '2027-12-01', '2027-12-04');

        $libres = app(HotelAvailability::class)
            ->habitacionesLibres((int) $this->hotel->id, '2027-12-02', '2027-12-03');

        $this->assertNotContains((int) $uno->id, $libres->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, $libres->count(), 'Debería quedar solo la otra habitación.');
    }

    // --------------------------------------------------------- auxiliares

    private function reserva(Room $habitacion, string $desde, string $hasta, string $nombre = 'Huésped ZZ'): Reservation
    {
        return app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'check_in_date' => $desde,
            'check_out_date' => $hasta,
            'guest_name' => $nombre,
            'adults' => 1,
        ]);
    }
}
