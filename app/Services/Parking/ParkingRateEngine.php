<?php

namespace App\Services\Parking;

use App\Models\Parking\ParkingRate;
use Carbon\CarbonInterface;

/**
 * Motor de tarifas de parqueadero. Calcula el monto a cobrar a partir de
 * una ParkingRate (con su JSON de reglas) y un par (entry_at, exit_at).
 *
 * ============================================================
 * SCHEMA del campo config (JSON) en parking_rates
 * ============================================================
 *
 *  type           string   "flat" | "per_minute" | "per_hour" | "per_day" | "tiered"
 *  amount         number   monto base segun type (no aplica a tiered)
 *  free_minutes   int      minutos de cortesia iniciales (no se cobran)
 *  rounding       string   "ceil" | "floor" | "round" | "none"  (default ceil)
 *  rounding_unit_min int   redondear minutos a multiplos de N (default 1)
 *  daily_cap      number   tope monetario maximo por sesion (null = sin tope)
 *  lost_ticket_amount number monto fijo a cobrar si el cliente pierde el ticket
 *
 *  tiers          array    solo para type=tiered: lista ordenada de escalones
 *    [
 *      { from_min: 0, to_min: 60,  type: "flat",       amount: 3000 },
 *      { from_min: 60, to_min: 180, type: "per_hour",   amount: 1000, rounding: "ceil" },
 *      { from_min: 180, to_min: null, type: "per_hour", amount: 2000 }
 *    ]
 *
 * ============================================================
 * SALIDA del metodo calculate()
 * ============================================================
 *
 *  minutes         int    duracion total en minutos
 *  free_minutes    int    minutos descontados por cortesia
 *  charge_minutes  int    minutos cobrados (minutes - free_minutes)
 *  amount          float  total a cobrar (ya con tope)
 *  cap_applied     bool   ¿se aplico el tope diario?
 *  breakdown       array  detalle linea por linea para mostrar al usuario
 *                         [ ['label' => string, 'amount' => float], ... ]
 */
class ParkingRateEngine
{
    /**
     * Calcula el monto a cobrar segun la tarifa y los timestamps.
     */
    public function calculate(ParkingRate $rate, CarbonInterface $entryAt, CarbonInterface $exitAt): array
    {
        $config = $rate->config ?? [];
        $minutes = max(0, (int) $entryAt->diffInMinutes($exitAt));
        $freeMinutes = (int) ($config['free_minutes'] ?? 0);
        $chargeMinutes = max(0, $minutes - $freeMinutes);

        if ($chargeMinutes === 0) {
            return [
                'minutes' => $minutes,
                'free_minutes' => min($freeMinutes, $minutes),
                'charge_minutes' => 0,
                'amount' => 0.0,
                'cap_applied' => false,
                'breakdown' => $minutes > 0
                    ? [['label' => "Cortesía: {$minutes} min", 'amount' => 0.0]]
                    : [],
            ];
        }

        $type = (string) ($config['type'] ?? 'flat');
        $result = match ($type) {
            'flat' => $this->calculateFlat($config),
            'per_minute' => $this->calculatePerMinute($config, $chargeMinutes),
            'per_hour' => $this->calculatePerHour($config, $chargeMinutes),
            'per_day' => $this->calculatePerDay($config, $chargeMinutes),
            'tiered' => $this->calculateTiered($config, $chargeMinutes),
            default => ['amount' => 0.0, 'breakdown' => []],
        };

        $amount = (float) $result['amount'];
        $breakdown = $result['breakdown'];

        // Si hubo minutos de cortesia, lo dejamos visible al inicio del desglose
        if ($freeMinutes > 0 && $minutes > $freeMinutes) {
            array_unshift($breakdown, [
                'label' => "Cortesía: {$freeMinutes} min sin cobro",
                'amount' => 0.0,
            ]);
        }

        // Tope diario
        $capApplied = false;
        $dailyCap = isset($config['daily_cap']) ? (float) $config['daily_cap'] : null;
        if ($dailyCap !== null && $dailyCap > 0 && $amount > $dailyCap) {
            $breakdown[] = [
                'label' => 'Tope diario aplicado',
                'amount' => -($amount - $dailyCap),
            ];
            $amount = $dailyCap;
            $capApplied = true;
        }

        return [
            'minutes' => $minutes,
            'free_minutes' => min($freeMinutes, $minutes),
            'charge_minutes' => $chargeMinutes,
            'amount' => round($amount, 2),
            'cap_applied' => $capApplied,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Monto a cobrar cuando el cliente reporta ticket perdido. Si la tarifa
     * tiene `lost_ticket_amount`, ese; si no, equivale a una estancia de 24h
     * sobre la tarifa (caso comun: regla del parqueadero "tarifa maxima del dia").
     */
    public function calculateLostTicket(ParkingRate $rate): array
    {
        $config = $rate->config ?? [];

        if (isset($config['lost_ticket_amount']) && (float) $config['lost_ticket_amount'] > 0) {
            $amount = (float) $config['lost_ticket_amount'];
            return [
                'minutes' => null,
                'free_minutes' => 0,
                'charge_minutes' => null,
                'amount' => round($amount, 2),
                'cap_applied' => false,
                'breakdown' => [['label' => 'Tarifa por ticket perdido', 'amount' => $amount]],
                'lost_ticket' => true,
            ];
        }

        // Fallback: cobrar 24h como si hubiera estado todo el día
        $now = now();
        $entry = $now->copy()->subDay();
        $simulated = $this->calculate($rate, $entry, $now);
        return array_merge($simulated, [
            'lost_ticket' => true,
            'breakdown' => array_merge(
                [['label' => 'Ticket perdido: se cobra estancia simulada de 24h', 'amount' => 0.0]],
                $simulated['breakdown'],
            ),
        ]);
    }

    /**
     * Encuentra la tarifa activa para (parqueadero, tipo de vehiculo, fecha).
     * Resolucion:
     *   1. tarifa vigente (active + valid_from/to) con vehicle_type_id especifico
     *   2. tarifa vigente con vehicle_type_id null (default del parqueadero)
     *   3. de las que matcheen, gana la de mayor priority
     */
    public function resolveActiveRate(int $parkingLotId, ?int $vehicleTypeId, CarbonInterface $at): ?ParkingRate
    {
        return ParkingRate::query()
            ->where('parking_lot_id', $parkingLotId)
            ->where('active', true)
            ->where(function ($q) use ($vehicleTypeId) {
                $q->whereNull('vehicle_type_id');
                if ($vehicleTypeId) {
                    $q->orWhere('vehicle_type_id', $vehicleTypeId);
                }
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $at);
            })
            ->orderByRaw('CASE WHEN vehicle_type_id IS NULL THEN 0 ELSE 1 END DESC') // especifica primero
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    // ============================================================
    // CALCULOS POR TIPO
    // ============================================================

    protected function calculateFlat(array $config): array
    {
        $amount = (float) ($config['amount'] ?? 0);
        return [
            'amount' => $amount,
            'breakdown' => [['label' => 'Tarifa plana', 'amount' => $amount]],
        ];
    }

    protected function calculatePerMinute(array $config, int $minutes): array
    {
        $unitRate = (float) ($config['amount'] ?? 0);
        $billable = $this->applyRounding(
            $minutes,
            (string) ($config['rounding'] ?? 'ceil'),
            (int) ($config['rounding_unit_min'] ?? 1),
        );
        $amount = $unitRate * $billable;
        return [
            'amount' => $amount,
            'breakdown' => [[
                'label' => "{$billable} min × $".number_format($unitRate, 0, ',', '.')."/min",
                'amount' => $amount,
            ]],
        ];
    }

    protected function calculatePerHour(array $config, int $minutes): array
    {
        $unitRate = (float) ($config['amount'] ?? 0);
        $rounding = (string) ($config['rounding'] ?? 'ceil');

        if ($rounding === 'none') {
            // Cobro proporcional (fraccion exacta de hora)
            $hours = $minutes / 60;
            $amount = $unitRate * $hours;
            return [
                'amount' => $amount,
                'breakdown' => [[
                    'label' => number_format($hours, 2).' h × $'.number_format($unitRate, 0, ',', '.').'/h',
                    'amount' => $amount,
                ]],
            ];
        }

        // Cobro por hora completa con redondeo
        $hours = $minutes / 60;
        $billable = match ($rounding) {
            'ceil' => (int) ceil($hours),
            'floor' => (int) floor($hours),
            'round' => (int) round($hours),
            default => (int) ceil($hours),
        };
        $billable = max(1, $billable); // minimo 1 hora si entra al cobro
        $amount = $unitRate * $billable;
        return [
            'amount' => $amount,
            'breakdown' => [[
                'label' => "{$billable} h × $".number_format($unitRate, 0, ',', '.')."/h",
                'amount' => $amount,
            ]],
        ];
    }

    protected function calculatePerDay(array $config, int $minutes): array
    {
        $unitRate = (float) ($config['amount'] ?? 0);
        $days = max(1, (int) ceil($minutes / (24 * 60)));
        $amount = $unitRate * $days;
        return [
            'amount' => $amount,
            'breakdown' => [[
                'label' => "{$days} día(s) × $".number_format($unitRate, 0, ',', '.')."/día",
                'amount' => $amount,
            ]],
        ];
    }

    protected function calculateTiered(array $config, int $minutes): array
    {
        $tiers = $config['tiers'] ?? [];
        if (empty($tiers)) {
            return ['amount' => 0.0, 'breakdown' => []];
        }

        $amount = 0.0;
        $breakdown = [];
        $remaining = $minutes;

        foreach ($tiers as $tier) {
            if ($remaining <= 0) break;

            $from = (int) ($tier['from_min'] ?? 0);
            $to = isset($tier['to_min']) && $tier['to_min'] !== null ? (int) $tier['to_min'] : null;

            // Cuantos minutos del intervalo de cobro caen dentro de este tier?
            // Asumimos que minutes son cobrados desde el inicio (minuto 0 cobrable
            // = primer minuto despues de la cortesia). El "from_min" es relativo
            // al minuto cobrable 0.
            $tierWidth = $to !== null ? max(0, $to - $from) : PHP_INT_MAX;
            $minutesInTier = min($remaining, $tierWidth);
            if ($minutesInTier <= 0) {
                continue;
            }

            $tierType = (string) ($tier['type'] ?? 'flat');
            $tierAmount = match ($tierType) {
                'flat' => (float) ($tier['amount'] ?? 0),
                'per_minute' => (float) ($tier['amount'] ?? 0) * $this->applyRounding(
                    $minutesInTier,
                    (string) ($tier['rounding'] ?? 'ceil'),
                    (int) ($tier['rounding_unit_min'] ?? 1),
                ),
                'per_hour' => (float) ($tier['amount'] ?? 0) * max(1, (int) ceil($minutesInTier / 60)),
                default => 0.0,
            };

            $rangeLabel = $from.'-'.($to ?? '∞').' min';
            $breakdown[] = [
                'label' => "{$rangeLabel}: ".$this->describeTier($tierType, $tier, $minutesInTier),
                'amount' => $tierAmount,
            ];
            $amount += $tierAmount;
            $remaining -= $minutesInTier;
        }

        return ['amount' => $amount, 'breakdown' => $breakdown];
    }

    // ============================================================
    // UTILS
    // ============================================================

    /**
     * Redondea minutos al multiplo configurado segun la estrategia. El
     * minimo siempre es el redondeo a multiplos de 1 (sin agrupar).
     */
    protected function applyRounding(int $minutes, string $strategy, int $unit): int
    {
        $unit = max(1, $unit);
        $blocks = $minutes / $unit;
        $rounded = match ($strategy) {
            'ceil' => ceil($blocks),
            'floor' => floor($blocks),
            'round' => round($blocks),
            default => $minutes,
        };
        return (int) ($rounded * $unit);
    }

    protected function describeTier(string $type, array $tier, int $minutesInTier): string
    {
        $rate = number_format((float) ($tier['amount'] ?? 0), 0, ',', '.');
        return match ($type) {
            'flat' => "tarifa plana \$$rate",
            'per_minute' => "{$minutesInTier} min × \$$rate/min",
            'per_hour' => max(1, (int) ceil($minutesInTier / 60))." h × \$$rate/h",
            default => "$rate",
        };
    }
}
