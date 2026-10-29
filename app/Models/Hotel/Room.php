<?php

namespace App\Models\Hotel;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La habitación.
 *
 * El estado es el del mapa de recepción y sigue un ciclo lineal:
 *
 *   disponible → (check-in) ocupada → (check-out) aseo → disponible
 *
 * `reserved` lo pone el recepcionista cuando quiere bloquear visualmente una
 * habitación apartada para hoy; NO es lo que decide la disponibilidad. Eso lo
 * decide HotelAvailability mirando reservas y estadías contra el rango de
 * fechas, porque una habitación libre hoy puede estar comprometida el viernes
 * y el estado de una sola columna no puede contar eso.
 */
class Room extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_rooms';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CLEANING = 'cleaning';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_AVAILABLE => 'Disponible',
        self::STATUS_OCCUPIED => 'Ocupada',
        self::STATUS_RESERVED => 'Reservada',
        self::STATUS_CLEANING => 'En aseo',
        self::STATUS_MAINTENANCE => 'Mantenimiento',
        self::STATUS_BLOCKED => 'Bloqueada',
    ];

    public const STATUS_COLORS = [
        self::STATUS_AVAILABLE => '#16a34a',    // verde
        self::STATUS_OCCUPIED => '#dc2626',     // rojo
        self::STATUS_RESERVED => '#f59e0b',     // ámbar
        self::STATUS_CLEANING => '#0ea5e9',     // azul
        self::STATUS_MAINTENANCE => '#6b7280',  // gris
        self::STATUS_BLOCKED => '#1f2937',      // gris oscuro
    ];

    /**
     * Estados que impiden alojar a alguien, pase lo que pase con las fechas.
     * Una habitación en mantenimiento no se vende ni para dentro de un mes.
     */
    public const STATUSES_NO_VENDIBLES = [
        self::STATUS_MAINTENANCE,
        self::STATUS_BLOCKED,
    ];

    protected $fillable = [
        'company_id',
        'hotel_id',
        'zone_id',
        'room_type_id',
        'number',
        'name',
        'floor',
        'status',
        'max_occupancy',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'max_occupancy' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(HotelZone::class, 'zone_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class, 'room_id');
    }

    /** La estadía abierta, si hay alguien dentro. */
    public function openStay(): HasMany
    {
        return $this->hasMany(Stay::class, 'room_id')
            ->where('status', Stay::STATUS_OPEN);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'room_id');
    }

    public function scopeVendibles(Builder $query): Builder
    {
        return $query->where('active', true)
            ->whereNotIn('status', self::STATUSES_NO_VENDIBLES);
    }

    public function estaOcupada(): bool
    {
        return $this->status === self::STATUS_OCCUPIED;
    }

    public function sePuedeVender(): bool
    {
        return (bool) $this->active
            && ! in_array($this->status, self::STATUSES_NO_VENDIBLES, true);
    }

    public function etiqueta(): string
    {
        return trim($this->number.($this->name ? ' · '.$this->name : ''));
    }
}
