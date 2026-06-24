<?php

namespace App\Models\Parking;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Espacio fisico de parqueo (slot). Pertenece a un ParkingLot, puede tener
 * zona (piso/sector), restriccion por tipo de vehiculo y bandera de
 * accesibilidad por discapacidad (ley colombiana).
 */
class ParkingSpace extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_FREE = 'free';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::STATUS_FREE => 'Libre',
        self::STATUS_OCCUPIED => 'Ocupado',
        self::STATUS_RESERVED => 'Reservado',
        self::STATUS_MAINTENANCE => 'Mantenimiento',
    ];

    public const STATUS_COLORS = [
        self::STATUS_FREE => '#16a34a',         // verde
        self::STATUS_OCCUPIED => '#dc2626',     // rojo
        self::STATUS_RESERVED => '#f59e0b',     // ámbar
        self::STATUS_MAINTENANCE => '#6b7280',  // gris
    ];

    protected $fillable = [
        'company_id',
        'parking_lot_id',
        'vehicle_type_id',
        'code',
        'zone',
        'status',
        'is_accessibility',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_accessibility' => 'boolean',
        ];
    }

    public function parkingLot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ParkingSession::class);
    }

    public function activeSession(): HasMany
    {
        return $this->hasMany(ParkingSession::class)
            ->where('status', ParkingSession::STATUS_ACTIVE);
    }

    public function scopeFree(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FREE);
    }

    public function isFree(): bool
    {
        return $this->status === self::STATUS_FREE;
    }
}
