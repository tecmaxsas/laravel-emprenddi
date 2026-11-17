<?php

namespace App\Services\Billiards;

use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardPause;
use App\Models\Billiards\BilliardSession;
use App\Models\Billiards\BilliardTable;
use App\Support\ClockFormat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El ciclo de vida de una mesa: abrir, pausar, reanudar, trasladar, cerrar
 * y anular.
 *
 * El cobro lo calcula BilliardRateEngine y lo escribe BilliardAccount; aqui
 * solo se decide CUANDO se escribe. La factura la emite
 * BilliardBillingEngine despues de cerrar.
 */
class BilliardSessionEngine
{
    public function __construct(
        protected BilliardRateEngine $rates,
        protected BilliardAccount $account,
    ) {}

    /**
     * Abre la mesa: empieza a correr el reloj.
     *
     * @param  array{table_id: int, players?: int, customer_label?: string,
     *               third_party_id?: int|null, notes?: string, started_at?: CarbonInterface}  $payload
     */
    public function abrir(array $payload): BilliardSession
    {
        $mesa = BilliardTable::query()
            ->with('hall')
            ->where('id', $payload['table_id'] ?? 0)
            ->first();

        if (! $mesa) {
            throw new RuntimeException('La mesa seleccionada no existe.');
        }

        if (! $mesa->active) {
            throw new RuntimeException("La mesa {$mesa->code} está desactivada.");
        }

        if ($mesa->status === BilliardTable::STATUS_MAINTENANCE) {
            throw new RuntimeException(
                "La mesa {$mesa->code} está en mantenimiento. Cámbiale el estado antes de abrirla."
            );
        }

        $hall = $mesa->hall;

        if (! $hall || ! $hall->active) {
            throw new RuntimeException('La sala de esta mesa no existe o está inactiva.');
        }

        $inicio = $payload['started_at'] ?? now();
        $tarifa = $this->rates->resolveFor($mesa, $inicio);

        return DB::transaction(function () use ($mesa, $hall, $payload, $inicio, $tarifa) {
            // Lock pesimista: dos meseros abriendo la misma mesa al tiempo es
            // el caso real de un viernes lleno, y el segundo tiene que
            // enterarse de que ya estaba tomada en vez de pisar la cuenta.
            $bloqueada = BilliardTable::query()->whereKey($mesa->id)->lockForUpdate()->first();

            if (! $bloqueada || ! $bloqueada->isFree()) {
                throw new RuntimeException(
                    "La mesa {$mesa->code} ya no está libre. Refresca el mapa del salón."
                );
            }

            $sesion = BilliardSession::create([
                'company_id' => $mesa->company_id,
                'hall_id' => $hall->id,
                'table_id' => $mesa->id,
                'table_type_id' => $mesa->table_type_id,
                'rate_id' => $tarifa?->id,
                'third_party_id' => $payload['third_party_id'] ?? null,
                'customer_label' => $this->etiqueta($payload['customer_label'] ?? null),
                'players' => max(1, (int) ($payload['players'] ?? 2)),
                'games' => 0,
                'started_at' => $inicio,
                'time_anchor_at' => $inicio,
                'status' => BilliardSession::STATUS_OPEN,
                'notes' => $payload['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $bloqueada->update(['status' => BilliardTable::STATUS_OCCUPIED]);

            return $sesion;
        });
    }

    /** Detiene el reloj. La mesa sigue tomada: nadie más se sienta ahí. */
    public function pausar(BilliardSession $sesion, ?string $motivo = null): BilliardSession
    {
        $this->exigirAbierta($sesion);

        if ($sesion->estaPausada()) {
            throw new RuntimeException('Esta mesa ya está en pausa.');
        }

        return DB::transaction(function () use ($sesion, $motivo) {
            $ahora = now();

            BilliardPause::create([
                'company_id' => $sesion->company_id,
                'session_id' => $sesion->id,
                'paused_at' => $ahora,
                'reason' => $motivo ? mb_substr(trim($motivo), 0, 255) : null,
                'paused_by_user_id' => Auth::id(),
            ]);

            $sesion->update(['paused_at' => $ahora]);

            return $sesion->fresh(['pauses']);
        });
    }

    public function reanudar(BilliardSession $sesion): BilliardSession
    {
        $this->exigirAbierta($sesion);

        if (! $sesion->estaPausada()) {
            throw new RuntimeException('Esta mesa no está en pausa.');
        }

        return DB::transaction(function () use ($sesion) {
            BilliardPause::query()
                ->where('session_id', $sesion->id)
                ->whereNull('resumed_at')
                ->update([
                    'resumed_at' => now(),
                    'resumed_by_user_id' => Auth::id(),
                    'updated_at' => now(),
                ]);

            $sesion->update(['paused_at' => null]);

            return $sesion->fresh(['pauses']);
        });
    }

    /**
     * Pasa la cuenta a otra mesa.
     *
     * El tiempo ya jugado se congela en su propio renglon con la tarifa de la
     * mesa vieja, y el reloj vuelve a arrancar en la nueva. Cobrarlo todo a
     * la tarifa de la ultima mesa seria cobrarle la mesa cara a quien se
     * cambio porque la primera tenia el paño roto.
     *
     * Si la mesa esta en pausa, se reanuda primero: una cuenta que se muda
     * con el reloj detenido llegaria a la mesa nueva sin que nadie se acuerde
     * de arrancarlo, y esas son horas que el salon regala.
     */
    public function trasladar(BilliardSession $sesion, BilliardTable $destino): BilliardSession
    {
        $this->exigirAbierta($sesion);

        if ((int) $destino->id === (int) $sesion->table_id) {
            throw new RuntimeException('La cuenta ya está en esa mesa.');
        }

        if ($destino->hall_id !== $sesion->hall_id) {
            throw new RuntimeException('La mesa de destino es de otra sala.');
        }

        if ($sesion->estaPausada()) {
            $sesion = $this->reanudar($sesion);
        }

        $origen = $sesion->table;

        return DB::transaction(function () use ($sesion, $destino, $origen) {
            $bloqueada = BilliardTable::query()->whereKey($destino->id)->lockForUpdate()->first();

            if (! $bloqueada || ! $bloqueada->isFree()) {
                throw new RuntimeException("La mesa {$destino->code} no está libre.");
            }

            $this->congelarTiempo($sesion, now());

            $origen?->update(['status' => BilliardTable::STATUS_FREE]);
            $bloqueada->update(['status' => BilliardTable::STATUS_OCCUPIED]);

            $ahora = now();
            $sesion->update([
                'table_id' => $bloqueada->id,
                'table_type_id' => $bloqueada->table_type_id,
                'rate_id' => $this->rates->resolveFor($bloqueada, $ahora)?->id,
                'time_anchor_at' => $ahora,
                'notes' => trim(($sesion->notes ? $sesion->notes."\n" : '')
                    .sprintf('Trasladada de %s a %s a las %s.',
                        $origen?->etiqueta() ?? '—',
                        $bloqueada->etiqueta(),
                        $ahora->format(ClockFormat::time()))),
            ]);

            return $sesion->fresh(['pauses', 'table']);
        });
    }

    /**
     * Cierra la cuenta: para el reloj, escribe el tiempo y libera la mesa.
     *
     * `$montoManual` reemplaza lo que calcula la tarifa (cortesia, precio
     * pactado del torneo, la mesa que se cobro a ojo). Queda en el desglose
     * lo que la tarifa habria cobrado, para que la diferencia se vea en los
     * reportes y no se pierda en la caja.
     */
    public function cerrar(
        BilliardSession $sesion,
        ?CarbonInterface $hasta = null,
        ?float $montoManual = null,
        ?int $partidas = null,
    ): BilliardSession {
        $this->exigirAbierta($sesion);

        $fin = $hasta ? Carbon::instance($hasta->toDateTime()) : now();

        if ($fin->lt($sesion->started_at)) {
            throw new RuntimeException('La hora de cierre no puede ser anterior a la de apertura.');
        }

        if ($partidas !== null) {
            $sesion->update(['games' => max(0, $partidas)]);
            $sesion->refresh();
        }

        return DB::transaction(function () use ($sesion, $fin, $montoManual) {
            $liquidacion = $this->congelarTiempo($sesion, $fin, $montoManual);

            $sesion->table?->update(['status' => BilliardTable::STATUS_FREE]);

            $sesion->update([
                'ended_at' => $fin,
                'paused_at' => null,
                'status' => BilliardSession::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'time_minutes' => $liquidacion['minutes'] ?? 0,
                'free_minutes' => $liquidacion['free_minutes'] ?? 0,
                'charge_minutes' => $liquidacion['charge_minutes'] ?? 0,
                'breakdown' => $liquidacion['breakdown'] ?? [],
            ]);

            // El cierre de la pausa abierta va despues de liquidar: el tiempo
            // se corta en `paused_at`, que es lo que el cliente vio detenido
            // en la pantalla.
            BilliardPause::query()
                ->where('session_id', $sesion->id)
                ->whereNull('resumed_at')
                ->update(['resumed_at' => $fin, 'updated_at' => now()]);

            return $this->account->recalcular($sesion->fresh());
        });
    }

    /**
     * Anula la cuenta: se abrio por error.
     *
     * No es lo mismo que cerrarla en cero. Una cuenta anulada no entro a la
     * caja y no deberia salir en los reportes de ingresos; una cerrada en
     * cero si —es una cortesia, y el dueño quiere saber cuantas dio—.
     */
    public function anular(BilliardSession $sesion, string $motivo): BilliardSession
    {
        $this->exigirAbierta($sesion);

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('Anular una cuenta exige un motivo: es lo que queda en la auditoría.');
        }

        if ($sesion->charges()->consumos()->exists()) {
            throw new RuntimeException(
                'Esta cuenta ya tiene consumos cargados. Quítalos primero o ciérrala y cóbrala: '
                .'anularla haría desaparecer productos que salieron de la nevera.'
            );
        }

        return DB::transaction(function () use ($sesion, $motivo) {
            $sesion->table?->update(['status' => BilliardTable::STATUS_FREE]);

            $sesion->update([
                'status' => BilliardSession::STATUS_CANCELLED,
                'ended_at' => now(),
                'paused_at' => null,
                'cancel_reason' => $motivo,
                'closed_at' => now(),
                'closed_by_user_id' => Auth::id(),
            ]);

            return $sesion->fresh();
        });
    }

    /**
     * Lo que va costando el tiempo ahora mismo, sin escribir nada.
     *
     * Lo usa el mapa del salon para mostrar el contador en vivo y la modal de
     * cobro para mostrar el desglose antes de cerrar.
     *
     * @return array{minutes: int, free_minutes: int, charge_minutes: int,
     *               amount: float, capped: bool, breakdown: list<array{label: string, amount: float}>}
     */
    public function cotizar(BilliardSession $sesion, ?CarbonInterface $hasta = null): array
    {
        $tarifa = $sesion->rate;

        if (! $tarifa) {
            return [
                'minutes' => $sesion->minutosJugados($hasta),
                'free_minutes' => 0,
                'charge_minutes' => 0,
                'amount' => 0.0,
                'capped' => false,
                'breakdown' => [[
                    'label' => 'Esta mesa no tiene tarifa configurada: el tiempo se cobra en $0.',
                    'amount' => 0.0,
                ]],
            ];
        }

        return $this->rates->calculate(
            $tarifa,
            $sesion->intervalosJugados($hasta),
            ['players' => (int) $sesion->players, 'games' => (int) $sesion->games],
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Liquida el tramo en curso y lo escribe como renglon de la cuenta.
     *
     * @return array<string, mixed> la liquidacion, para que el que cierra
     *                              pueda guardar minutos y desglose
     */
    protected function congelarTiempo(
        BilliardSession $sesion,
        CarbonInterface $hasta,
        ?float $montoManual = null,
    ): array {
        $liquidacion = $this->cotizar($sesion, $hasta);

        if ($montoManual !== null) {
            $calculado = (float) $liquidacion['amount'];
            $liquidacion['breakdown'][] = [
                'label' => sprintf('Valor digitado a mano (la tarifa calculaba $%s)',
                    number_format($calculado, 0, ',', '.')),
                'amount' => round($montoManual - $calculado, 2),
            ];
            $liquidacion['amount'] = round(max(0, $montoManual), 2);
        }

        $this->account->agregarTiempo(
            $sesion,
            $liquidacion,
            $this->descripcionDelTiempo($sesion, $liquidacion, $hasta),
        );

        return $liquidacion;
    }

    protected function descripcionDelTiempo(
        BilliardSession $sesion,
        array $liquidacion,
        CarbonInterface $hasta,
    ): string {
        $mesa = $sesion->table;
        $tipo = $sesion->tableType?->name;

        return sprintf(
            '%s%s — %s (%s a %s)',
            $mesa?->etiqueta() ?? 'Mesa',
            $tipo ? ' ('.$tipo.')' : '',
            $this->rates->enHoras((int) ($liquidacion['charge_minutes'] ?? 0)),
            $sesion->time_anchor_at?->format(ClockFormat::time()) ?? '—',
            $hasta->format(ClockFormat::time()),
        );
    }

    protected function etiqueta(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, 120);
    }

    protected function exigirAbierta(BilliardSession $sesion): void
    {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Esta cuenta ya fue cerrada o anulada.');
        }
    }

    /** Las mesas de una sala con su cuenta abierta, listas para pintar el mapa. */
    public function mapaDeLaSala(BilliardHall $sala)
    {
        return BilliardTable::query()
            ->with(['tableType', 'openSessions.pauses', 'openSessions.rate'])
            ->where('hall_id', $sala->id)
            ->where('active', true)
            ->orderBy('zone')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }
}
