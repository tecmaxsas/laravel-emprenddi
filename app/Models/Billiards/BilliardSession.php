<?php

namespace App\Models\Billiards;

use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La cuenta de una mesa: quien esta jugando, desde cuando y cuanto debe.
 *
 * Ciclo de vida:
 *   open       la mesa esta tomada (jugando o en pausa)
 *   closed     se liquido y se cobro
 *   cancelled  anulada con motivo (se abrio por error)
 *
 * La pausa NO es un estado. Es `paused_at`: si tiene hora, el reloj esta
 * detenido. Tener las dos cosas —un status 'paused' y una marca de tiempo—
 * seria tener dos verdades sobre lo mismo, y el dia que una se actualice
 * sin la otra habria mesas cobrando tiempo mientras la pantalla las muestra
 * detenidas.
 */
class BilliardSession extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Abierta',
        self::STATUS_CLOSED => 'Cerrada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    protected $fillable = [
        'company_id',
        'hall_id',
        'table_id',
        'table_type_id',
        'rate_id',
        'third_party_id',
        'customer_label',
        'players',
        'games',
        'started_at',
        'time_anchor_at',
        'ended_at',
        'paused_at',
        'status',
        'time_minutes',
        'free_minutes',
        'charge_minutes',
        'breakdown',
        'time_total',
        'consumption_total',
        'discount_total',
        'tax_total',
        'total',
        'paid_amount',
        'payment_method',
        'sale_invoice_id',
        'notes',
        'cancel_reason',
        'created_by_user_id',
        'closed_by_user_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'time_anchor_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
            'closed_at' => 'datetime',
            'players' => 'integer',
            'games' => 'integer',
            'time_minutes' => 'integer',
            'free_minutes' => 'integer',
            'charge_minutes' => 'integer',
            'breakdown' => 'array',
            'time_total' => 'decimal:2',
            'consumption_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(BilliardHall::class, 'hall_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(BilliardTable::class, 'table_id');
    }

    public function tableType(): BelongsTo
    {
        return $this->belongsTo(BilliardTableType::class, 'table_type_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(BilliardRate::class, 'rate_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(BilliardCharge::class, 'session_id');
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(BilliardPause::class, 'session_id');
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function estaAbierta(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function estaPausada(): bool
    {
        return $this->estaAbierta() && $this->paused_at !== null;
    }

    public function etiquetaDelEstado(): string
    {
        if ($this->estaPausada()) {
            return 'En pausa';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Los tramos que de verdad se jugaron: el reloj menos las pausas.
     *
     * Esta aqui y no en el motor de tarifas porque es la sesion la que sabe
     * cuando arranco y cuando la pararon. El motor recibe los tramos ya
     * resueltos y se queda siendo una funcion pura de horas y precios.
     *
     * Cuenta desde `time_anchor_at` y no desde `started_at`: tras un traslado
     * de mesa, el tiempo anterior ya quedo cobrado en su propio renglon a la
     * tarifa de aquella mesa.
     *
     * Mientras la mesa esta en pausa el reloj se queda quieto en la hora en
     * que se detuvo.
     *
     * OJO: lee la relacion `pauses`. El mapa del salon la trae con eager
     * load; sin eso serian tantas consultas como mesas ocupadas, en cada
     * repintada.
     *
     * @return list<array{0: CarbonInterface, 1: CarbonInterface}>
     */
    public function intervalosJugados(?CarbonInterface $hasta = null): array
    {
        if (! $this->time_anchor_at) {
            return [];
        }

        $inicio = $this->time_anchor_at->copy();
        $fin = $this->paused_at?->copy()
            ?: ($this->ended_at?->copy() ?: ($hasta ? $hasta->copy() : now()));

        if ($fin->lte($inicio)) {
            return [];
        }

        $pausas = $this->pauses
            ->filter(fn (BilliardPause $p) => $p->resumed_at !== null && $p->resumed_at->gt($inicio))
            ->sortBy('paused_at');

        $intervalos = [];
        $cursor = $inicio;

        foreach ($pausas as $pausa) {
            $desdePausa = $pausa->paused_at->copy();
            $hastaPausa = $pausa->resumed_at->copy();

            if ($desdePausa->gte($fin)) {
                break;
            }

            if ($desdePausa->gt($cursor)) {
                $intervalos[] = [$cursor->copy(), $desdePausa->min($fin)->copy()];
            }

            if ($hastaPausa->gt($cursor)) {
                $cursor = $hastaPausa;
            }
        }

        if ($cursor->lt($fin)) {
            $intervalos[] = [$cursor->copy(), $fin->copy()];
        }

        return $intervalos;
    }

    /** Minutos jugados del tramo en curso, descontando lo pausado. */
    public function minutosJugados(?CarbonInterface $hasta = null): int
    {
        $total = 0;

        foreach ($this->intervalosJugados($hasta) as [$desde, $final]) {
            $total += max(0, (int) $desde->diffInMinutes($final));
        }

        return $total;
    }

    /** Minutos desde que llegaron, pausas incluidas. Para mostrar, no para cobrar. */
    public function minutosEnMesa(): int
    {
        if (! $this->started_at) {
            return 0;
        }

        $corte = $this->ended_at ?: now();

        return max(0, (int) $this->started_at->diffInMinutes($corte));
    }

    /**
     * Lo que va debiendo la mesa ahora mismo.
     *
     * `total` es lo que esta escrito en la cuenta: los consumos, y el tiempo
     * solo despues de cerrar. Mientras juegan, el tiempo todavia no es un
     * renglon —esta corriendo— asi que el mapa del salon suma aparte la
     * proyeccion que le pase el motor de tarifas.
     */
    public function totalProyectado(float $tiempoEnCurso = 0.0): float
    {
        return round((float) $this->total + $tiempoEnCurso, 2);
    }
}
