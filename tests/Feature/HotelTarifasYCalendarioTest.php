<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hotel\RateSeason;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\Location;
use App\Models\User;
use App\Services\Hotel\HotelAvailability;
use App\Services\Hotel\HotelFolio;
use App\Services\Hotel\HotelReports;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelStayEngine;
use App\Services\Hotel\HotelTarifas;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * Tarifas por temporada, calendario y pronóstico.
 *
 * Las tres cosas se apoyan en lo mismo: que el sistema sepa qué pasa **cada
 * día**, y no solo hoy.
 *
 * Lo que más plata mueve es la temporada. Un hotel que cambia la tarifa a mano
 * el 15 de diciembre tiene que acordarse de devolverla el 15 de enero, y no se
 * acuerda: o vende diciembre al precio de noviembre, o sigue cobrando tarifa
 * alta en febrero y el huésped lo nota.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class HotelTarifasYCalendarioTest extends TestCase
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
        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 2, tarifa: 100000);

        app(HotelTarifas::class)->olvidarCache();
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Temporadas */
    /* ------------------------------------------------------------------ */

    /** Sin temporada, manda la tarifa del tipo. */
    public function test_sin_temporada_vale_la_tarifa_del_tipo(): void
    {
        $this->assertEqualsWithDelta(
            100000,
            app(HotelTarifas::class)->paraNoche($this->tipoDoble, '2027-02-10'),
            0.01,
        );
    }

    /** Dentro de la temporada, manda la temporada. */
    public function test_la_temporada_manda_sobre_la_tarifa_base(): void
    {
        $this->temporada('ZZ Alta', '2027-12-15', '2028-01-15', 250000);

        $tarifas = app(HotelTarifas::class);

        $this->assertEqualsWithDelta(250000, $tarifas->paraNoche($this->tipoDoble, '2027-12-20'), 0.01);
        $this->assertEqualsWithDelta(100000, $tarifas->paraNoche($this->tipoDoble, '2027-11-20'), 0.01,
            'Fuera del rango tiene que volver a la tarifa del tipo.');
    }

    /**
     * Las dos fechas de una temporada son INCLUSIVAS.
     *
     * Al revés que el rango de una estadía, y a propósito: «del 15 de
     * diciembre al 15 de enero» incluye los dos, que es como lo dice
     * cualquiera. Si el último día quedara fuera, el hotel vendería la noche
     * más cara del año al precio normal.
     */
    public function test_los_dos_extremos_de_la_temporada_cuentan(): void
    {
        $this->temporada('ZZ Puente', '2027-08-14', '2027-08-16', 300000);

        $tarifas = app(HotelTarifas::class);

        $this->assertEqualsWithDelta(300000, $tarifas->paraNoche($this->tipoDoble, '2027-08-14'), 0.01, 'El primer día.');
        $this->assertEqualsWithDelta(300000, $tarifas->paraNoche($this->tipoDoble, '2027-08-16'), 0.01, 'El último día.');
        $this->assertEqualsWithDelta(100000, $tarifas->paraNoche($this->tipoDoble, '2027-08-17'), 0.01);
    }

    /**
     * Cuando dos temporadas se pisan, gana la de mayor prioridad.
     *
     * Es lo que deja meter un puente caro de tres días dentro de una temporada
     * alta de un mes, sin tener que partir la temporada en tres.
     */
    public function test_la_prioridad_resuelve_el_empate(): void
    {
        $this->temporada('ZZ Alta', '2027-12-15', '2028-01-15', 250000, prioridad: 10);
        $this->temporada('ZZ Fin de año', '2027-12-30', '2028-01-01', 500000, prioridad: 50);

        $tarifas = app(HotelTarifas::class);

        $this->assertEqualsWithDelta(500000, $tarifas->paraNoche($this->tipoDoble, '2027-12-31'), 0.01,
            'El puente tiene más prioridad: tiene que ganar.');
        $this->assertEqualsWithDelta(250000, $tarifas->paraNoche($this->tipoDoble, '2027-12-20'), 0.01,
            'Fuera del puente vuelve a mandar la temporada alta.');
    }

    /** Una temporada apagada no cobra. */
    public function test_una_temporada_inactiva_no_aplica(): void
    {
        $temporada = $this->temporada('ZZ Alta', '2027-12-15', '2028-01-15', 250000);
        $temporada->update(['active' => false]);
        app(HotelTarifas::class)->olvidarCache();

        $this->assertEqualsWithDelta(
            100000,
            app(HotelTarifas::class)->paraNoche($this->tipoDoble, '2027-12-20'),
            0.01,
        );
    }

    /**
     * Una cotización que cruza temporadas cobra cada noche a lo suyo.
     *
     * Promediar sería cobrarle de menos al hotel unos días y de más al huésped
     * otros, y las dos cosas se notan.
     */
    public function test_la_cotizacion_cruza_temporadas_noche_por_noche(): void
    {
        $this->temporada('ZZ Alta', '2027-12-15', '2028-01-15', 200000, prioridad: 10);
        $this->temporada('ZZ Fin de año', '2027-12-31', '2027-12-31', 500000, prioridad: 50);

        // Del 30 al 2 son tres noches: 30 (alta), 31 (fin de año), 1 (alta).
        $cotizacion = app(HotelTarifas::class)->cotizar($this->tipoDoble, '2027-12-30', '2028-01-02');

        $this->assertSame(3, $cotizacion['noches']);
        $this->assertEqualsWithDelta(900000, $cotizacion['total'], 0.01, '200.000 + 500.000 + 200.000');
        $this->assertEqualsWithDelta(300000, $cotizacion['promedio'], 0.01);
        $this->assertCount(2, $cotizacion['temporadas']);
    }

    /* ------------------------------------------------------------------ */
    /*  La temporada llega hasta el folio */
    /* ------------------------------------------------------------------ */

    /**
     * Cada noche del folio nace con el precio de su fecha.
     *
     * No basta con cotizar bien: si el folio cobra todas las noches a la misma
     * tarifa, la cotización era una promesa que la cuenta no cumple.
     */
    public function test_el_folio_cobra_cada_noche_a_su_tarifa(): void
    {
        $entrada = now()->startOfDay();

        // La segunda noche vale el triple.
        $this->temporada('ZZ Puente', $entrada->copy()->addDay()->toDateString(),
            $entrada->copy()->addDay()->toDateString(), 300000, prioridad: 50);

        $estadia = $this->alojar(3);

        app(HotelFolio::class)->cargarNochesPendientes($estadia, $entrada->copy()->addDays(2));

        $noches = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->noches()
            ->orderBy('night_date')
            ->get();

        $this->assertCount(3, $noches);
        $this->assertEqualsWithDelta(100000, (float) $noches[0]->total, 0.01, 'Primera noche, tarifa normal.');
        $this->assertEqualsWithDelta(300000, (float) $noches[1]->total, 0.01, 'Segunda noche, el puente.');
        $this->assertEqualsWithDelta(100000, (float) $noches[2]->total, 0.01, 'Tercera noche, normal otra vez.');
    }

    /**
     * Una tarifa pactada no se reajusta por temporada.
     *
     * Un convenio corporativo o una negociación de grupo es un precio cerrado.
     * Subírselo porque cayó un puente en el medio es incumplir lo acordado.
     */
    public function test_la_tarifa_pactada_le_gana_a_la_temporada(): void
    {
        $entrada = now()->startOfDay();

        $this->temporada('ZZ Puente', $entrada->toDateString(),
            $entrada->copy()->addDays(3)->toDateString(), 300000, prioridad: 50);

        // Se pacta 120.000 a pesar del puente.
        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => $entrada->copy()->addDays(2)->toDateString(),
            'rate_per_night' => 120000,
        ]);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $this->assertTrue((bool) $estadia->rate_is_fixed, 'Una tarifa distinta a la cotizada queda pactada.');

        $noche = StayCharge::query()->where('stay_id', $estadia->id)->noches()->firstOrFail();

        $this->assertEqualsWithDelta(120000, (float) $noche->total, 0.01,
            'Se pactó 120.000: el puente no se le aplica por encima.');
    }

    /** Una reserva se cotiza con las temporadas de sus fechas. */
    public function test_la_reserva_estima_con_temporadas(): void
    {
        $entrada = now()->addDays(40)->startOfDay();

        $this->temporada('ZZ Alta', $entrada->toDateString(),
            $entrada->copy()->addDays(5)->toDateString(), 250000);

        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => $entrada->toDateString(),
            'check_out_date' => $entrada->copy()->addDays(2)->toDateString(),
            'guest_name' => 'Huésped ZZ temporada',
        ]);

        $this->assertEqualsWithDelta(500000, (float) $reserva->estimated_total, 0.01,
            'Dos noches de temporada alta: 250.000 cada una.');
        $this->assertFalse((bool) $reserva->rate_is_fixed);
    }

    /* ------------------------------------------------------------------ */
    /*  Calendario */
    /* ------------------------------------------------------------------ */

    /** Lo que ocupa cada habitación, día por día. */
    public function test_el_calendario_pinta_estadias_y_reservas(): void
    {
        $entrada = now()->startOfDay();
        $estadia = $this->alojar(2);

        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 2)->id,
            'check_in_date' => $entrada->copy()->addDays(3)->toDateString(),
            'check_out_date' => $entrada->copy()->addDays(5)->toDateString(),
            'guest_name' => 'Reservado ZZ cal',
        ]);

        $calendario = app(HotelAvailability::class)->calendario($this->hotel->id, $entrada, 7);

        $uno = (int) $this->habitacion($this->hotel, 1)->id;
        $dos = (int) $this->habitacion($this->hotel, 2)->id;

        $this->assertSame('estadia', $calendario['ocupacion'][$uno][$entrada->toDateString()]['tipo'] ?? null);
        $this->assertSame(
            'reserva',
            $calendario['ocupacion'][$dos][$entrada->copy()->addDays(3)->toDateString()]['tipo'] ?? null,
        );

        // La noche de salida de la reserva ya está libre: el rango es
        // [entrada, salida) igual que en todo el módulo.
        $this->assertArrayNotHasKey(
            $entrada->copy()->addDays(5)->toDateString(),
            $calendario['ocupacion'][$dos] ?? [],
            'La noche de salida no se ocupa.',
        );

        unset($estadia, $reserva);
    }

    /**
     * El calendario dice lo mismo que la disponibilidad.
     *
     * Es la prueba que importa de verdad: si el calendario pintara libre una
     * noche que el check-in rechaza, el recepcionista dejaría de creerle a la
     * pantalla y el calendario no serviría para nada.
     */
    public function test_el_calendario_no_contradice_a_la_disponibilidad(): void
    {
        $entrada = now()->startOfDay();
        $this->alojar(3);

        $disponibilidad = app(HotelAvailability::class);
        $calendario = $disponibilidad->calendario($this->hotel->id, $entrada, 7);

        foreach ($calendario['habitaciones'] as $habitacion) {
            foreach ($calendario['dias'] as $dia) {
                $celda = $calendario['ocupacion'][$habitacion->id][$dia] ?? null;

                $pintadoLibre = $celda === null;

                $realmenteLibre = $disponibilidad->estaLibre(
                    $habitacion,
                    $dia,
                    \Illuminate\Support\Carbon::parse($dia)->addDay(),
                );

                // Una estadía ya cerrada se pinta ocupada en el pasado pero la
                // habitación sí se puede volver a vender: ese es el único caso
                // en que difieren, y es correcto.
                if ($celda && $celda['tipo'] === 'cerrada') {
                    continue;
                }

                $this->assertSame(
                    $pintadoLibre,
                    $realmenteLibre,
                    "La habitación {$habitacion->number} el {$dia} se pinta "
                    .($pintadoLibre ? 'libre' : 'ocupada').' y la disponibilidad dice lo contrario.',
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pronóstico */
    /* ------------------------------------------------------------------ */

    /** Lo reservado hacia adelante aparece en el pronóstico. */
    public function test_el_pronostico_cuenta_lo_que_viene(): void
    {
        $entrada = now()->addDays(3)->startOfDay();

        app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => $entrada->toDateString(),
            'check_out_date' => $entrada->copy()->addDays(2)->toDateString(),
            'guest_name' => 'Futuro ZZ',
        ]);

        $pronostico = app(HotelReports::class)->pronostico([
            'hotel_id' => $this->hotel->id,
            'desde' => now()->toDateString(),
            'dias' => 10,
        ]);

        $dia = $pronostico['filas']->firstWhere('dia', $entrada->toDateString());

        $this->assertNotNull($dia);
        $this->assertSame(1, $dia['reservados']);
        $this->assertSame(2, $dia['vendibles'], 'El hotel de prueba tiene dos habitaciones.');
        $this->assertEqualsWithDelta(50.0, $dia['ocupacion'], 0.1);
        $this->assertGreaterThan(0, $dia['ingreso'], 'La noche comprometida tiene que valer algo.');
    }

    /** Y el ingreso previsto usa la tarifa de cada fecha. */
    public function test_el_pronostico_valora_con_la_temporada(): void
    {
        $entrada = now()->addDays(3)->startOfDay();

        $this->temporada('ZZ Alta', $entrada->toDateString(), $entrada->toDateString(), 400000, prioridad: 50);

        app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'check_in_date' => $entrada->toDateString(),
            'check_out_date' => $entrada->copy()->addDay()->toDateString(),
            'guest_name' => 'Caro ZZ',
        ]);

        $pronostico = app(HotelReports::class)->pronostico([
            'hotel_id' => $this->hotel->id,
            'desde' => now()->toDateString(),
            'dias' => 10,
        ]);

        $dia = $pronostico['filas']->firstWhere('dia', $entrada->toDateString());

        $this->assertEqualsWithDelta(400000, $dia['ingreso'], 0.01,
            'Esa noche es temporada alta: el pronóstico tiene que valorarla así.');
    }

    // --------------------------------------------------------- auxiliares

    private function temporada(
        string $nombre,
        string $desde,
        string $hasta,
        float $tarifa,
        int $prioridad = 0,
    ): RateSeason {
        $temporada = RateSeason::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => null,
            'name' => $nombre,
            'start_date' => $desde,
            'end_date' => $hasta,
            'rate' => $tarifa,
            'priority' => $prioridad,
            'active' => true,
        ]);

        app(HotelTarifas::class)->olvidarCache();

        $this->limpiar[] = function () use ($temporada) {
            DB::table('hotel_rate_seasons')->where('id', $temporada->id)->delete();
        };

        return $temporada;
    }

    private function alojar(int $noches): Stay
    {
        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->copy()->addDays($noches)->toDateString(),
        ]);

        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        return $estadia;
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
}
