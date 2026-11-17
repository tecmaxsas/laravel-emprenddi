<?php

namespace App\Services\Billiards;

use App\Models\Billiards\BilliardRate;
use App\Models\Billiards\BilliardTable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;

/**
 * Motor de tarifas de billar: cuanto cuesta el tiempo que estuvieron en la
 * mesa.
 *
 * ============================================================
 * SCHEMA del campo config (JSON) en billiard_rates
 * ============================================================
 *
 *  type              "per_hour" | "per_minute" | "flat" | "per_game" | "tiered"
 *  amount            valor base segun type ($/hora, $/minuto, $ fijo, $/partida)
 *  free_minutes      cortesia inicial que no se cobra
 *  minimum_minutes   minimo que se cobra aunque jueguen menos ("minimo una hora")
 *  minimum_amount    cobro minimo en pesos
 *  max_amount        tope por cuenta
 *  rounding          "ceil" | "floor" | "round" | "none"   (default ceil)
 *  rounding_unit_min bloque de redondeo en minutos (default 1).
 *                    60 = se cobra hora completa empezada.
 *  per_player        bool — la tarifa se multiplica por el numero de jugadores
 *  schedules         franjas horarias (ver abajo)
 *  tiers             escalones (solo type=tiered)
 *
 * ------------------------------------------------------------
 * FRANJAS (`schedules`) — lo que de verdad diferencia a un billar
 * ------------------------------------------------------------
 *
 *  [
 *    { "label": "Happy hour", "days": [1,2,3,4], "from": "14:00", "to": "18:00", "amount": 6000 },
 *    { "label": "Noche",      "days": [5,6],     "from": "20:00", "to": "02:00", "amount": 14000 }
 *  ]
 *
 *  days   dias ISO (1=lunes … 7=domingo). Vacio o ausente = todos.
 *  from/to  "HH:MM". Si `to` es menor que `from`, la franja cruza la
 *           medianoche (20:00 a 02:00 es la noche del viernes).
 *  amount   reemplaza al `amount` base mientras la franja este vigente.
 *
 *  Gana la PRIMERA franja que coincida, asi que el orden de la lista es el
 *  orden de prioridad. Lo que no cae en ninguna se cobra al `amount` base.
 *
 *  Una cuenta que empieza a las 5 de la tarde y termina a las 8 de la noche
 *  NO se cobra toda a una sola tarifa: se parte en tramos y cada tramo paga
 *  la suya. Cobrarle todo a la tarifa de la hora de salida es cobrarle la
 *  noche a quien llego en happy hour, y es el reclamo que vuelve.
 *
 * ------------------------------------------------------------
 * REDONDEO Y MINIMOS
 * ------------------------------------------------------------
 *
 *  Los minutos que se agregan por redondeo o por minimo se cobran a la
 *  tarifa del ULTIMO tramo jugado — la que estaba vigente al soltar el taco.
 *  Es lo que se puede explicar en el mostrador: «se le completo la hora a la
 *  tarifa de la noche, que es la que estaba corriendo».
 *
 * ============================================================
 * SALIDA de calculate()
 * ============================================================
 *
 *  minutes         int    minutos jugados (sin pausas)
 *  free_minutes    int    minutos descontados por cortesia
 *  charge_minutes  int    minutos cobrados (ya con minimo y redondeo)
 *  amount          float  total a cobrar
 *  capped          bool   ¿se aplico el tope?
 *  breakdown       array  [ ['label' => string, 'amount' => float], ... ]
 */
class BilliardRateEngine
{
    /** Tope de tramos por cuenta. Una franja mal escrita no puede colgar la caja. */
    protected const MAX_TRAMOS = 500;

    /**
     * La tarifa que le toca a esta mesa en este momento.
     *
     * Gana la de mayor prioridad; a igual prioridad, la que nombra el tipo
     * de mesa le gana a la generica de la sala. Si dos tarifas quedan
     * iguales en todo, gana la mas nueva: es la que alguien acaba de
     * configurar, y la vieja seguramente se quedo ahi por olvido.
     */
    public function resolveFor(BilliardTable $table, ?CarbonInterface $at = null): ?BilliardRate
    {
        $at = $at ?: now();

        return BilliardRate::query()
            ->where('hall_id', $table->hall_id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('table_type_id')
                ->orWhere('table_type_id', $table->table_type_id))
            ->get()
            ->filter(fn (BilliardRate $r) => $r->isValidAt($at))
            ->sortByDesc(fn (BilliardRate $r) => [
                (int) $r->priority,
                $r->table_type_id ? 1 : 0,
                (int) $r->id,
            ])
            ->first();
    }

    /**
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $intervalos
     *                                                                          Los tramos que de verdad se jugaron: la cuenta menos las pausas.
     *                                                                          Se reciben ya resueltos —y no la sesion entera— para que el motor
     *                                                                          sea una funcion pura: se puede probar con horas inventadas sin
     *                                                                          montar mesas ni pausas en la base.
     * @param  array{players?: int, games?: int}  $contexto
     */
    public function calculate(BilliardRate $rate, array $intervalos, array $contexto = []): array
    {
        $config = $rate->config ?? [];
        $type = (string) ($config['type'] ?? 'per_hour');
        $jugadores = max(1, (int) ($contexto['players'] ?? 1));
        $partidas = max(0, (int) ($contexto['games'] ?? 0));

        $minutos = 0;
        foreach ($intervalos as [$desde, $hasta]) {
            $minutos += max(0, (int) $desde->diffInMinutes($hasta));
        }

        // Las tarifas que no miran el reloj se resuelven aparte: una mesa de
        // rana se cobra por partida aunque se demoren toda la tarde.
        if ($type === 'flat' || $type === 'per_game') {
            return $this->sinReloj($config, $type, $minutos, $partidas, $jugadores);
        }

        $libres = max(0, (int) ($config['free_minutes'] ?? 0));

        $tramos = $this->tramos($config, $intervalos);
        $tramos = $this->descontarCortesia($tramos, $libres);

        $cobrables = array_sum(array_column($tramos, 'minutes'));
        $objetivo = $this->redondear(
            max($cobrables, (int) ($config['minimum_minutes'] ?? 0)),
            $config,
        );

        $tramos = $this->completarHasta($tramos, $objetivo, $cobrables, $config);

        $desglose = [];
        $total = 0.0;

        if ($libres > 0 && $minutos > 0) {
            $desglose[] = [
                'label' => 'Cortesía: '.min($libres, $minutos).' min sin cobro',
                'amount' => 0.0,
            ];
        }

        foreach ($tramos as $tramo) {
            if ($tramo['minutes'] <= 0) {
                continue;
            }

            $valor = $type === 'tiered'
                ? 0.0
                : $this->valorDelTramo($type, (float) $tramo['amount'], (int) $tramo['minutes']);

            $total += $valor;

            if ($type !== 'tiered') {
                $desglose[] = [
                    'label' => sprintf(
                        '%s%s a %s',
                        $tramo['label'] ? $tramo['label'].': ' : '',
                        $this->enHoras((int) $tramo['minutes']),
                        $this->porUnidad($type, (float) $tramo['amount']),
                    ),
                    'amount' => round($valor, 2),
                ];
            }
        }

        // Los escalones no se parten por franja: un escalon ya ES una regla
        // sobre el tiempo total ("la primera hora vale X, de ahi en adelante
        // Y"), y cruzarlo con las franjas daria dos reglas peleando por el
        // mismo minuto. Se cobran sobre el total cobrado.
        if ($type === 'tiered') {
            $escalones = $this->porEscalones($config, $objetivo);
            $total = $escalones['amount'];
            $desglose = array_merge($desglose, $escalones['breakdown']);
        }

        if (! empty($config['per_player']) && $jugadores > 1) {
            $antes = $total;
            $total *= $jugadores;
            $desglose[] = [
                'label' => "Tarifa por jugador × {$jugadores}",
                'amount' => round($total - $antes, 2),
            ];
        }

        [$total, $topado, $desglose] = $this->aplicarMinimoYTope($config, $total, $desglose);

        return [
            'minutes' => $minutos,
            'free_minutes' => min($libres, $minutos),
            'charge_minutes' => $objetivo,
            'amount' => round($total, 2),
            'capped' => $topado,
            'breakdown' => $desglose,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Franjas                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Parte los intervalos jugados en tramos de tarifa constante.
     *
     * Se camina por cortes y no minuto a minuto: una cuenta que alguien dejo
     * abierta un fin de semana son tres mil iteraciones de Carbon por cada
     * repintada del mapa del salon, y el mapa se repinta solo.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $intervalos
     * @return list<array{amount: float, label: ?string, minutes: int}>
     */
    protected function tramos(array $config, array $intervalos): array
    {
        $base = (float) ($config['amount'] ?? 0);
        $franjas = is_array($config['schedules'] ?? null) ? $config['schedules'] : [];

        $tramos = [];

        foreach ($intervalos as [$desde, $hasta]) {
            if ($hasta->lte($desde)) {
                continue;
            }

            $cursor = Carbon::instance($desde->toDateTime());
            $fin = Carbon::instance($hasta->toDateTime());
            $vueltas = 0;

            while ($cursor->lt($fin) && $vueltas++ < self::MAX_TRAMOS) {
                $franja = $this->franjaVigente($franjas, $cursor);
                $corte = $this->siguienteCorte($franjas, $cursor, $fin);

                $minutos = max(0, (int) $cursor->diffInMinutes($corte));

                if ($minutos > 0) {
                    $tramos[] = [
                        'amount' => $franja ? (float) ($franja['amount'] ?? $base) : $base,
                        'label' => $franja['label'] ?? null,
                        'minutes' => $minutos,
                    ];
                }

                $cursor = $corte;
            }

            // Red de seguridad: si una franja mal escrita impidio avanzar,
            // el resto se cobra a la tarifa base en vez de perderse.
            if ($cursor->lt($fin)) {
                $tramos[] = [
                    'amount' => $base,
                    'label' => null,
                    'minutes' => max(0, (int) $cursor->diffInMinutes($fin)),
                ];
            }
        }

        return $this->fundirTramosIguales($tramos);
    }

    /** Dos tramos seguidos con la misma tarifa son un solo renglon en la cuenta. */
    protected function fundirTramosIguales(array $tramos): array
    {
        $out = [];

        foreach ($tramos as $tramo) {
            $ultimo = count($out) - 1;

            if ($ultimo >= 0
                && $out[$ultimo]['amount'] === $tramo['amount']
                && $out[$ultimo]['label'] === $tramo['label']) {
                $out[$ultimo]['minutes'] += $tramo['minutes'];

                continue;
            }

            $out[] = $tramo;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    protected function franjaVigente(array $franjas, CarbonInterface $t): ?array
    {
        foreach ($franjas as $franja) {
            if ($this->franjaCubre($franja, $t)) {
                return $franja;
            }
        }

        return null;
    }

    protected function franjaCubre(array $franja, CarbonInterface $t): bool
    {
        $dias = array_map('intval', (array) ($franja['days'] ?? []));
        $desde = $this->minutosDelDia($franja['from'] ?? null);
        $hasta = $this->minutosDelDia($franja['to'] ?? null);

        if ($desde === null || $hasta === null) {
            return false;
        }

        $ahora = $t->hour * 60 + $t->minute;

        // Una franja que cruza la medianoche (22:00 a 02:00) pertenece al dia
        // en que EMPIEZA: la "noche del viernes" incluye las dos de la mañana
        // del sabado, y es asi como la piensa el dueño del salon.
        if ($hasta <= $desde) {
            if ($ahora >= $desde) {
                return $this->diaAplica($dias, $t->dayOfWeekIso);
            }

            if ($ahora < $hasta) {
                $ayer = $t->copy()->subDay()->dayOfWeekIso;

                return $this->diaAplica($dias, $ayer);
            }

            return false;
        }

        return $ahora >= $desde && $ahora < $hasta && $this->diaAplica($dias, $t->dayOfWeekIso);
    }

    protected function diaAplica(array $dias, int $diaIso): bool
    {
        return $dias === [] || in_array($diaIso, $dias, true);
    }

    /**
     * El proximo instante en que la tarifa puede cambiar: el inicio o el fin
     * de cualquier franja, o la medianoche (donde cambia el dia de la semana
     * y con el, que franjas aplican).
     */
    protected function siguienteCorte(array $franjas, Carbon $t, Carbon $tope): Carbon
    {
        $candidatos = [$t->copy()->addDay()->startOfDay()];

        foreach ($franjas as $franja) {
            foreach (['from', 'to'] as $clave) {
                $minutos = $this->minutosDelDia($franja[$clave] ?? null);

                if ($minutos === null) {
                    continue;
                }

                foreach ([0, 1] as $dias) {
                    $candidato = $t->copy()->startOfDay()
                        ->addDays($dias)
                        ->addMinutes($minutos);

                    if ($candidato->gt($t)) {
                        $candidatos[] = $candidato;
                    }
                }
            }
        }

        $candidatos = array_filter($candidatos, fn (Carbon $c) => $c->gt($t));
        $corte = $candidatos === [] ? $tope : min($candidatos);

        return $corte->lt($tope) ? $corte : $tope;
    }

    protected function minutosDelDia(?string $hora): ?int
    {
        if (! $hora || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($hora), $m)) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];

        if ($h > 24 || $min > 59) {
            return null;
        }

        return min(24 * 60, $h * 60 + $min);
    }

    /* ------------------------------------------------------------------ */
    /*  Cortesia, minimos, redondeo                                        */
    /* ------------------------------------------------------------------ */

    /** La cortesia se come los primeros minutos, que es como la entiende el cliente. */
    protected function descontarCortesia(array $tramos, int $libres): array
    {
        foreach ($tramos as $i => $tramo) {
            if ($libres <= 0) {
                break;
            }

            $quita = min($libres, (int) $tramo['minutes']);
            $tramos[$i]['minutes'] -= $quita;
            $libres -= $quita;
        }

        return array_values(array_filter($tramos, fn ($t) => $t['minutes'] > 0));
    }

    protected function redondear(int $minutos, array $config): int
    {
        $unidad = max(1, (int) ($config['rounding_unit_min'] ?? 1));
        $modo = (string) ($config['rounding'] ?? 'ceil');

        if ($minutos <= 0) {
            return 0;
        }

        return match ($modo) {
            'none' => $minutos,
            'floor' => intdiv($minutos, $unidad) * $unidad,
            'round' => (int) (round($minutos / $unidad) * $unidad),
            default => (int) (ceil($minutos / $unidad) * $unidad),
        };
    }

    /**
     * Los minutos que agregan el minimo y el redondeo se cobran a la tarifa
     * del ultimo tramo: la que estaba corriendo cuando soltaron el taco.
     */
    protected function completarHasta(array $tramos, int $objetivo, int $cobrables, array $config): array
    {
        $faltan = $objetivo - $cobrables;

        if ($faltan <= 0) {
            return $tramos;
        }

        if ($tramos === []) {
            return [[
                'amount' => (float) ($config['amount'] ?? 0),
                'label' => 'Mínimo de la tarifa',
                'minutes' => $faltan,
            ]];
        }

        $ultimo = count($tramos) - 1;
        $tramos[$ultimo]['minutes'] += $faltan;

        return $tramos;
    }

    protected function aplicarMinimoYTope(array $config, float $total, array $desglose): array
    {
        $minimo = (float) ($config['minimum_amount'] ?? 0);

        if ($minimo > 0 && $total < $minimo) {
            $desglose[] = [
                'label' => 'Cobro mínimo de la tarifa',
                'amount' => round($minimo - $total, 2),
            ];
            $total = $minimo;
        }

        $tope = isset($config['max_amount']) ? (float) $config['max_amount'] : null;
        $topado = false;

        if ($tope !== null && $tope > 0 && $total > $tope) {
            $desglose[] = [
                'label' => 'Tope de la tarifa aplicado',
                'amount' => -round($total - $tope, 2),
            ];
            $total = $tope;
            $topado = true;
        }

        return [$total, $topado, $desglose];
    }

    /* ------------------------------------------------------------------ */
    /*  Tipos de tarifa                                                    */
    /* ------------------------------------------------------------------ */

    protected function valorDelTramo(string $type, float $tarifa, int $minutos): float
    {
        return match ($type) {
            'per_minute' => $tarifa * $minutos,
            default => $tarifa * ($minutos / 60),
        };
    }

    protected function porUnidad(string $type, float $tarifa): string
    {
        $valor = '$'.number_format($tarifa, 0, ',', '.');

        return $type === 'per_minute' ? $valor.'/min' : $valor.'/hora';
    }

    protected function sinReloj(array $config, string $type, int $minutos, int $partidas, int $jugadores): array
    {
        $base = (float) ($config['amount'] ?? 0);

        if ($type === 'per_game') {
            $cantidad = max(0, $partidas);
            $total = $base * $cantidad;
            $desglose = [[
                'label' => sprintf('%d partida%s a $%s', $cantidad, $cantidad === 1 ? '' : 's',
                    number_format($base, 0, ',', '.')),
                'amount' => round($total, 2),
            ]];
        } else {
            $total = $base;
            $desglose = [[
                'label' => 'Tarifa fija por mesa',
                'amount' => round($total, 2),
            ]];
        }

        if (! empty($config['per_player']) && $jugadores > 1) {
            $antes = $total;
            $total *= $jugadores;
            $desglose[] = [
                'label' => "Tarifa por jugador × {$jugadores}",
                'amount' => round($total - $antes, 2),
            ];
        }

        [$total, $topado, $desglose] = $this->aplicarMinimoYTope($config, $total, $desglose);

        return [
            'minutes' => $minutos,
            'free_minutes' => 0,
            'charge_minutes' => $minutos,
            'amount' => round($total, 2),
            'capped' => $topado,
            'breakdown' => $desglose,
        ];
    }

    /**
     * Escalones sobre el tiempo total: "la primera hora $8.000, de ahi en
     * adelante $6.000 la hora".
     *
     *  tiers: [ {from_min, to_min|null, type, amount}, ... ]
     */
    protected function porEscalones(array $config, int $minutos): array
    {
        $tiers = is_array($config['tiers'] ?? null) ? $config['tiers'] : [];
        $total = 0.0;
        $desglose = [];
        $restantes = $minutos;
        $consumidos = 0;

        foreach ($tiers as $tier) {
            if ($restantes <= 0) {
                break;
            }

            $desde = (int) ($tier['from_min'] ?? 0);
            $hasta = isset($tier['to_min']) && $tier['to_min'] !== null ? (int) $tier['to_min'] : null;

            if ($consumidos < $desde) {
                continue;
            }

            $ancho = $hasta === null ? $restantes : max(0, $hasta - $desde);
            $enEste = min($restantes, $ancho);

            if ($enEste <= 0) {
                continue;
            }

            $tarifa = (float) ($tier['amount'] ?? 0);
            $tipo = (string) ($tier['type'] ?? 'per_hour');
            $valor = $tipo === 'flat'
                ? $tarifa
                : $this->valorDelTramo($tipo, $tarifa, $enEste);

            $total += $valor;
            $desglose[] = [
                'label' => sprintf('%s (%s)', $this->enHoras($enEste),
                    $tipo === 'flat' ? 'tarifa fija' : $this->porUnidad($tipo, $tarifa)),
                'amount' => round($valor, 2),
            ];

            $restantes -= $enEste;
            $consumidos += $enEste;
        }

        if ($restantes > 0) {
            $tarifa = (float) ($config['amount'] ?? 0);
            $valor = $this->valorDelTramo('per_hour', $tarifa, $restantes);
            $total += $valor;
            $desglose[] = [
                'label' => sprintf('%s a %s (fuera de los escalones)',
                    $this->enHoras($restantes), $this->porUnidad('per_hour', $tarifa)),
                'amount' => round($valor, 2),
            ];
        }

        return ['amount' => $total, 'breakdown' => $desglose];
    }

    /** «1 h 25 min» — como lo lee el cliente, no 85. */
    public function enHoras(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos.' min';
        }

        $intervalo = CarbonInterval::minutes($minutos)->cascade();
        $horas = $intervalo->totalHours >= 1 ? (int) floor($minutos / 60) : 0;
        $resto = $minutos % 60;

        return $resto === 0 ? $horas.' h' : $horas.' h '.$resto.' min';
    }
}
