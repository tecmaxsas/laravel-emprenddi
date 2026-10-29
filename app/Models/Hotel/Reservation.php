<?php

namespace App\Models\Hotel;

use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reserva.
 *
 * Puede nacer sin habitación: el cliente aparta «una doble para el viernes» y
 * el número se decide después, a veces el mismo día. Por eso lo que se aparta
 * es el tipo (`room_type_id`) y `room_id` se llena al anclarla.
 *
 * El rango es [check_in_date, check_out_date): quien sale el 10 deja la
 * habitación libre para quien llega el 10.
 */
class Reservation extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_reservations';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING => 'Por confirmar',
        self::STATUS_CONFIRMED => 'Confirmada',
        self::STATUS_CHECKED_IN => 'Alojado',
        self::STATUS_NO_SHOW => 'No llegó',
        self::STATUS_CANCELLED => 'Cancelada',
        self::STATUS_COMPLETED => 'Terminada',
    ];

    /**
     * Los estados que comprometen la habitación.
     *
     * Solo estos dos bloquean fechas. Una cancelada o una que no llegó
     * liberan lo que tenían apartado, y una que ya hizo check-in la bloquea
     * a través de su estadía, no de la reserva —si contara dos veces, el
     * huésped se chocaría consigo mismo al pedir extender la salida.
     */
    public const STATUSES_QUE_BLOQUEAN = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
    ];

    public const SOURCES = [
        'direct' => 'Directa / mostrador',
        'phone' => 'Teléfono',
        'whatsapp' => 'WhatsApp',
        'walk_in' => 'Llegó sin reserva',
        'booking' => 'Booking.com',
        'airbnb' => 'Airbnb',
        'agency' => 'Agencia de viajes',
        'other' => 'Otra',
    ];

    protected $fillable = [
        'company_id',
        'hotel_id',
        'room_id',
        'room_type_id',
        'third_party_id',
        'guest_id',
        'stay_id',
        'code',
        'guest_name',
        'guest_phone',
        'guest_email',
        'check_in_date',
        'check_out_date',
        'nights',
        'adults',
        'children',
        'rate_per_night',
        'estimated_total',
        'deposit_amount',
        'source',
        'status',
        'confirmed_at',
        'cancelled_at',
        'cancel_reason',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'nights' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'rate_per_night' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class, 'stay_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeQueBloquean(Builder $query): Builder
    {
        return $query->whereIn('status', self::STATUSES_QUE_BLOQUEAN);
    }

    /** Reservas que llegan hoy y todavía no han hecho check-in. */
    public function scopeLlegadasDe(Builder $query, $fecha): Builder
    {
        return $query->whereDate('check_in_date', $fecha)
            ->whereIn('status', self::STATUSES_QUE_BLOQUEAN);
    }

    public function estaViva(): bool
    {
        return in_array($this->status, self::STATUSES_QUE_BLOQUEAN, true);
    }

    public function puedeHacerCheckIn(): bool
    {
        return $this->estaViva() && ! $this->stay_id;
    }

    public function nombreDelHuesped(): string
    {
        return $this->guest?->nombreCompleto() ?: ($this->guest_name ?: 'Sin nombre');
    }
}
