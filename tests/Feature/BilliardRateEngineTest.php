<?php

namespace Tests\Feature;

use App\Models\Billiards\BilliardRate;
use App\Services\Billiards\BilliardRateEngine;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El motor de tarifas de billar.
 *
 * No toca la base: recibe los tramos jugados y una tarifa sin guardar. Son
 * las reglas de plata del módulo, y poder probarlas con horas inventadas es
 * justo lo que permite escribir los casos raros —la noche que cruza la
 * medianoche, el happy hour que se acaba a mitad de partida— sin montar un
 * salón entero.
 */
class BilliardRateEngineTest extends TestCase
{
    private BilliardRateEngine $motor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->motor = app(BilliardRateEngine::class);
    }

    /** Dos horas a $10.000 son $20.000. */
    public function test_cobra_por_hora(): void
    {
        $r = $this->calcular(
            ['type' => 'per_hour', 'amount' => 10000],
            '14:00', '16:00',
        );

        $this->assertSame(120, $r['minutes']);
        $this->assertEqualsWithDelta(20000, $r['amount'], 0.01);
    }

    /**
     * La hora empezada se cobra completa.
     *
     * Es la regla más común del país: el aviso dice «hora $10.000» y 1 h 10
     * min se cobran como dos.
     */
    public function test_la_hora_empezada_se_cobra_completa(): void
    {
        $r = $this->calcular(
            ['type' => 'per_hour', 'amount' => 10000, 'rounding_unit_min' => 60],
            '14:00', '15:10',
        );

        $this->assertSame(120, $r['charge_minutes']);
        $this->assertEqualsWithDelta(20000, $r['amount'], 0.01);
    }

    /** La cortesía se come los primeros minutos. */
    public function test_los_minutos_de_cortesia_no_se_cobran(): void
    {
        $r = $this->calcular(
            ['type' => 'per_hour', 'amount' => 10000, 'free_minutes' => 15],
            '14:00', '15:15',
        );

        $this->assertSame(15, $r['free_minutes']);
        $this->assertSame(60, $r['charge_minutes']);
        $this->assertEqualsWithDelta(10000, $r['amount'], 0.01);
    }

    /** El mínimo se cobra aunque jueguen menos. */
    public function test_el_minimo_se_cobra_aunque_jueguen_menos(): void
    {
        $r = $this->calcular(
            ['type' => 'per_hour', 'amount' => 10000, 'minimum_minutes' => 60],
            '14:00', '14:20',
        );

        $this->assertSame(20, $r['minutes']);
        $this->assertSame(60, $r['charge_minutes']);
        $this->assertEqualsWithDelta(10000, $r['amount'], 0.01);
    }

    /**
     * Lo que de verdad diferencia a un billar: la cuenta que cruza de franja.
     *
     * Entraron a las 5 en happy hour y salieron a las 7, ya en tarifa normal.
     * Cobrarles las dos horas a la tarifa de la salida es el reclamo que
     * vuelve; cobrárselas a la de la entrada es regalar plata.
     */
    public function test_la_cuenta_que_cruza_de_franja_se_parte_en_dos(): void
    {
        $r = $this->calcular(
            [
                'type' => 'per_hour',
                'amount' => 12000,
                'schedules' => [
                    ['label' => 'Happy hour', 'from' => '14:00', 'to' => '18:00', 'amount' => 6000],
                ],
            ],
            '17:00', '19:00',
        );

        $this->assertEqualsWithDelta(18000, $r['amount'], 0.01,
            'Una hora de happy hour a $6.000 más una hora normal a $12.000.');
        $this->assertCount(2, $r['breakdown'], 'El desglose tiene que mostrar los dos tramos.');
    }

    /** La franja de la noche se lleva las dos de la mañana del día siguiente. */
    public function test_la_franja_de_la_noche_cruza_la_medianoche(): void
    {
        $r = $this->calcular(
            [
                'type' => 'per_hour',
                'amount' => 8000,
                'schedules' => [
                    ['label' => 'Noche', 'from' => '22:00', 'to' => '02:00', 'amount' => 14000],
                ],
            ],
            '21:30', '00:30', cruzaDia: true,
        );

        // 30 min a $8.000/h = $4.000 · 2 h 30 min a $14.000/h = $35.000
        $this->assertEqualsWithDelta(39000, $r['amount'], 0.01);
    }

    /** Una franja de solo viernes y sábado no aplica un martes. */
    public function test_la_franja_respeta_los_dias(): void
    {
        $martes = Carbon::parse('2026-11-17 20:00:00');   // martes
        $this->assertSame(2, $martes->dayOfWeekIso, 'El caso depende de que sea martes.');

        $r = $this->motor->calculate(
            $this->tarifa([
                'type' => 'per_hour',
                'amount' => 8000,
                'schedules' => [
                    ['label' => 'Fin de semana', 'days' => [5, 6], 'from' => '20:00', 'to' => '23:00', 'amount' => 20000],
                ],
            ]),
            [[$martes, $martes->copy()->addHour()]],
        );

        $this->assertEqualsWithDelta(8000, $r['amount'], 0.01,
            'La franja de viernes y sábado no puede cobrarse un martes.');
    }

    /** El tope corta la cuenta de la noche larga. */
    public function test_el_tope_corta_la_cuenta(): void
    {
        $r = $this->calcular(
            ['type' => 'per_hour', 'amount' => 10000, 'max_amount' => 50000],
            '14:00', '23:00',
        );

        $this->assertTrue($r['capped']);
        $this->assertEqualsWithDelta(50000, $r['amount'], 0.01);
    }

    /** La rana se cobra por partida, no por reloj. */
    public function test_cobra_por_partida(): void
    {
        $r = $this->motor->calculate(
            $this->tarifa(['type' => 'per_game', 'amount' => 5000]),
            [[Carbon::parse('2026-11-17 14:00'), Carbon::parse('2026-11-17 17:00')]],
            ['games' => 3],
        );

        $this->assertEqualsWithDelta(15000, $r['amount'], 0.01,
            'Tres partidas a $5.000, sin importar las tres horas que se demoraron.');
    }

    /** Hay salones que cobran por cabeza. */
    public function test_puede_cobrar_por_jugador(): void
    {
        $r = $this->motor->calculate(
            $this->tarifa(['type' => 'per_hour', 'amount' => 10000, 'per_player' => true]),
            [[Carbon::parse('2026-11-17 14:00'), Carbon::parse('2026-11-17 16:00')]],
            ['players' => 3],
        );

        $this->assertEqualsWithDelta(60000, $r['amount'], 0.01);
    }

    /** «La primera hora $8.000, de ahí en adelante $6.000». */
    public function test_cobra_por_escalones(): void
    {
        $r = $this->calcular(
            [
                'type' => 'tiered',
                'amount' => 6000,
                'tiers' => [
                    ['from_min' => 0, 'to_min' => 60, 'type' => 'flat', 'amount' => 8000],
                    ['from_min' => 60, 'to_min' => null, 'type' => 'per_hour', 'amount' => 6000],
                ],
            ],
            '14:00', '16:30',
        );

        // $8.000 la primera hora + 1 h 30 min a $6.000/h = $9.000
        $this->assertEqualsWithDelta(17000, $r['amount'], 0.01);
    }

    /** El tiempo pausado no se cobra: son dos tramos, no uno. */
    public function test_el_tiempo_pausado_no_se_cobra(): void
    {
        $tarifa = $this->tarifa(['type' => 'per_hour', 'amount' => 10000]);

        $r = $this->motor->calculate($tarifa, [
            [Carbon::parse('2026-11-17 14:00'), Carbon::parse('2026-11-17 15:00')],
            [Carbon::parse('2026-11-17 16:00'), Carbon::parse('2026-11-17 17:00')],
        ]);

        $this->assertSame(120, $r['minutes'], 'Dos horas jugadas, la del medio no cuenta.');
        $this->assertEqualsWithDelta(20000, $r['amount'], 0.01);
    }

    /** Sin tiempo jugado y sin mínimo, no hay nada que cobrar. */
    public function test_sin_tiempo_no_cobra_nada(): void
    {
        $r = $this->motor->calculate($this->tarifa(['type' => 'per_hour', 'amount' => 10000]), []);

        $this->assertSame(0, $r['minutes']);
        $this->assertEqualsWithDelta(0, $r['amount'], 0.01);
    }

    /**
     * Una franja mal escrita no puede colgar la caja.
     *
     * `from` igual a `to` no delimita nada; el motor tiene que salir con un
     * cobro en vez de quedarse girando con el cliente en el mostrador.
     */
    public function test_una_franja_mal_escrita_no_cuelga_el_cobro(): void
    {
        $r = $this->calcular(
            [
                'type' => 'per_hour',
                'amount' => 10000,
                'schedules' => [['from' => '14:00', 'to' => '14:00', 'amount' => 1]],
            ],
            '14:00', '16:00',
        );

        $this->assertGreaterThan(0, $r['amount']);
        $this->assertSame(120, $r['minutes']);
    }

    // --------------------------------------------------------- auxiliares

    private function calcular(array $config, string $desde, string $hasta, bool $cruzaDia = false): array
    {
        $inicio = Carbon::parse('2026-11-17 '.$desde);
        $fin = Carbon::parse(($cruzaDia ? '2026-11-18 ' : '2026-11-17 ').$hasta);

        return $this->motor->calculate($this->tarifa($config), [[$inicio, $fin]]);
    }

    private function tarifa(array $config): BilliardRate
    {
        // Sin guardar: el motor solo lee `config`, así que la prueba no
        // necesita empresa, sala ni mesa.
        return new BilliardRate(['config' => $config]);
    }
}
