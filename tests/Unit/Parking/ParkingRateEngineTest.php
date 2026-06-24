<?php

namespace Tests\Unit\Parking;

use App\Models\Parking\ParkingRate;
use App\Services\Parking\ParkingRateEngine;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Cubre cada tipo de tarifa y combinaciones con cortesia y tope diario.
 * Usa PHPUnit\TestCase (no la base de Laravel) para que corra sin DB.
 */
class ParkingRateEngineTest extends TestCase
{
    protected ParkingRateEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ParkingRateEngine();
    }

    protected function makeRate(array $config): ParkingRate
    {
        $rate = new ParkingRate();
        $rate->config = $config;
        return $rate;
    }

    // ============================================================
    // FLAT
    // ============================================================

    public function test_flat_rate_charges_fixed_amount_regardless_of_duration(): void
    {
        $rate = $this->makeRate(['type' => 'flat', 'amount' => 5000]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(45));

        $this->assertSame(5000.0, $result['amount']);
        $this->assertSame(45, $result['minutes']);
    }

    // ============================================================
    // PER MINUTE
    // ============================================================

    public function test_per_minute_with_ceil_rounds_up(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_minute',
            'amount' => 100,
            'rounding' => 'ceil',
            'rounding_unit_min' => 5,
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 23 min con rounding a 5 -> 25 min cobrables
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(23));

        $this->assertSame(2500.0, $result['amount']);
        $this->assertSame(23, $result['minutes']);
    }

    public function test_per_minute_with_floor_rounds_down(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_minute',
            'amount' => 100,
            'rounding' => 'floor',
            'rounding_unit_min' => 10,
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 47 min con rounding floor 10 -> 40 min cobrables
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(47));
        $this->assertSame(4000.0, $result['amount']);
    }

    // ============================================================
    // PER HOUR
    // ============================================================

    public function test_per_hour_ceil_charges_complete_hour_at_first_minute(): void
    {
        $rate = $this->makeRate(['type' => 'per_hour', 'amount' => 3000, 'rounding' => 'ceil']);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 1 min adentro de la 2a hora -> 2 horas cobradas
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(61));
        $this->assertSame(6000.0, $result['amount']);
    }

    public function test_per_hour_proportional_with_none_rounding(): void
    {
        $rate = $this->makeRate(['type' => 'per_hour', 'amount' => 6000, 'rounding' => 'none']);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 30 min -> 0.5 horas * 6000 = 3000
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(30));
        $this->assertSame(3000.0, $result['amount']);
    }

    // ============================================================
    // PER DAY
    // ============================================================

    public function test_per_day_aeropuerto_cobra_dia_completo_desde_minuto_1(): void
    {
        $rate = $this->makeRate(['type' => 'per_day', 'amount' => 25000]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 5 horas -> 1 dia
        $r1 = $this->engine->calculate($rate, $entry, $entry->copy()->addHours(5));
        $this->assertSame(25000.0, $r1['amount']);

        // 25 horas -> 2 dias
        $r2 = $this->engine->calculate($rate, $entry, $entry->copy()->addHours(25));
        $this->assertSame(50000.0, $r2['amount']);
    }

    // ============================================================
    // FREE MINUTES (cortesia)
    // ============================================================

    public function test_free_minutes_zero_charge_if_under_courtesy(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_hour',
            'amount' => 3000,
            'free_minutes' => 10,
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 8 min - dentro de cortesia -> $0
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(8));
        $this->assertSame(0.0, $result['amount']);
        $this->assertSame(8, $result['free_minutes']);
    }

    public function test_free_minutes_discounted_from_billable(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_minute',
            'amount' => 100,
            'rounding' => 'ceil',
            'rounding_unit_min' => 1,
            'free_minutes' => 10,
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 30 min total - 10 cortesia = 20 cobrables * 100 = 2000
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(30));
        $this->assertSame(2000.0, $result['amount']);
        $this->assertSame(10, $result['free_minutes']);
        $this->assertSame(20, $result['charge_minutes']);
    }

    // ============================================================
    // DAILY CAP (tope)
    // ============================================================

    public function test_daily_cap_truncates_amount_when_exceeded(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_hour',
            'amount' => 3000,
            'rounding' => 'ceil',
            'daily_cap' => 15000,
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 8 horas a 3000 = 24000, pero tope 15000
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addHours(8));
        $this->assertSame(15000.0, $result['amount']);
        $this->assertTrue($result['cap_applied']);
    }

    public function test_daily_cap_not_applied_when_below_threshold(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_hour',
            'amount' => 3000,
            'rounding' => 'ceil',
            'daily_cap' => 50000,
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addHours(2));
        $this->assertSame(6000.0, $result['amount']);
        $this->assertFalse($result['cap_applied']);
    }

    // ============================================================
    // TIERED
    // ============================================================

    public function test_tiered_first_hour_free_then_per_hour(): void
    {
        $rate = $this->makeRate([
            'type' => 'tiered',
            'tiers' => [
                ['from_min' => 0, 'to_min' => 60, 'type' => 'flat', 'amount' => 0],
                ['from_min' => 60, 'to_min' => null, 'type' => 'per_hour', 'amount' => 2000],
            ],
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');

        // 45 min -> tier 1 (gratis) solo, sin pasar a tier 2
        $r1 = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(45));
        $this->assertSame(0.0, $r1['amount']);

        // 90 min -> tier 1 cubre 60 min, tier 2 cubre 30 min (1 hora ceil)
        $r2 = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(90));
        $this->assertSame(2000.0, $r2['amount']);
    }

    public function test_tiered_three_levels_first_30_free_then_two_brackets(): void
    {
        $rate = $this->makeRate([
            'type' => 'tiered',
            'tiers' => [
                ['from_min' => 0, 'to_min' => 30, 'type' => 'flat', 'amount' => 0],
                ['from_min' => 30, 'to_min' => 180, 'type' => 'per_hour', 'amount' => 1000],
                ['from_min' => 180, 'to_min' => null, 'type' => 'per_hour', 'amount' => 2000],
            ],
        ]);
        $entry = Carbon::parse('2026-06-05 10:00:00');
        // 4 horas (240 min):
        //   tier 1: 30 min gratis
        //   tier 2: 150 min (2.5h ceil -> 3h) * 1000 = 3000
        //   tier 3: 60 min (1h) * 2000 = 2000
        // total = 5000
        $result = $this->engine->calculate($rate, $entry, $entry->copy()->addMinutes(240));
        $this->assertSame(5000.0, $result['amount']);
    }

    // ============================================================
    // TICKET PERDIDO
    // ============================================================

    public function test_lost_ticket_uses_configured_amount(): void
    {
        $rate = $this->makeRate([
            'type' => 'flat',
            'amount' => 5000,
            'lost_ticket_amount' => 30000,
        ]);
        $result = $this->engine->calculateLostTicket($rate);
        $this->assertSame(30000.0, $result['amount']);
        $this->assertTrue($result['lost_ticket']);
    }

    public function test_lost_ticket_fallback_simulates_24h(): void
    {
        $rate = $this->makeRate([
            'type' => 'per_hour',
            'amount' => 3000,
            'rounding' => 'ceil',
            // sin lost_ticket_amount
        ]);
        $result = $this->engine->calculateLostTicket($rate);
        // 24 horas * 3000 = 72000
        $this->assertSame(72000.0, $result['amount']);
        $this->assertTrue($result['lost_ticket']);
    }
}
