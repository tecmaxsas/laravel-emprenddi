<?php

namespace App\Models\Parking;

use App\Models\SaleInvoice;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sesion de parqueo de un vehiculo. Ciclo de vida:
 *
 *   active        vehiculo dentro del parqueadero, sin salida
 *   closed        salio y se cobro la tarifa calculada
 *   lost_ticket   salio con cobro por ticket perdido
 *   cancelled     anulada manualmente (con motivo)
 */
class ParkingSession extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LOST_TICKET = 'lost_ticket';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'En parqueadero',
        self::STATUS_CLOSED => 'Salida (normal)',
        self::STATUS_LOST_TICKET => 'Salida (ticket perdido)',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    protected $fillable = [
        'company_id',
        'parking_lot_id',
        'vehicle_type_id',
        'rate_id',
        'plate',
        'space_code',
        'entry_photo_path',
        'exit_photo_path',
        'entry_at',
        'exit_at',
        'status',
        'total_minutes',
        'free_minutes',
        'charge_minutes',
        'amount',
        'cap_applied',
        'breakdown',
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
            'entry_at' => 'datetime',
            'exit_at' => 'datetime',
            'closed_at' => 'datetime',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'cap_applied' => 'boolean',
            'breakdown' => 'array',
            'total_minutes' => 'integer',
            'free_minutes' => 'integer',
            'charge_minutes' => 'integer',
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

    public function rate(): BelongsTo
    {
        return $this->belongsTo(ParkingRate::class, 'rate_id');
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
