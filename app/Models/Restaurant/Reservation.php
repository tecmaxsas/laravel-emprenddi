<?php

namespace App\Models\Restaurant;

use App\Models\Location;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_reservations';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SEATED = 'seated';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_CONFIRMED => 'Confirmada',
        self::STATUS_SEATED => 'Sentado',
        self::STATUS_NO_SHOW => 'No vino',
        self::STATUS_CANCELLED => 'Cancelada',
        self::STATUS_COMPLETED => 'Completada',
    ];

    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'gray',
        self::STATUS_CONFIRMED => 'info',
        self::STATUS_SEATED => 'success',
        self::STATUS_NO_SHOW => 'danger',
        self::STATUS_CANCELLED => 'warning',
        self::STATUS_COMPLETED => 'success',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'table_id',
        'zone_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'reserved_for',
        'duration_minutes',
        'guests',
        'status',
        'notes',
        'confirmed_at',
        'seated_at',
        'cancelled_at',
        'seated_order_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'reserved_for' => 'datetime',
            'duration_minutes' => 'integer',
            'guests' => 'integer',
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }

    public function seatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'seated_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
        ], true);
    }

    /**
     * "Próxima" = en los siguientes 120 minutos o ya en hora ±30 min.
     */
    public function isUpcoming(): bool
    {
        if (! $this->isActive()) return false;
        $now = now();
        return $this->reserved_for->between(
            $now->copy()->subMinutes(30),
            $now->copy()->addMinutes(120),
        );
    }
}
