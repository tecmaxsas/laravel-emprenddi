<?php

namespace App\Models\Restaurant;

use App\Models\CashRegisterSession;
use App\Models\Location;
use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    protected $table = 'restaurant_orders';

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_KITCHEN = 'in_kitchen';
    public const STATUS_SERVED = 'served';
    public const STATUS_BILLING = 'billing';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Abierta',
        self::STATUS_IN_KITCHEN => 'En cocina',
        self::STATUS_SERVED => 'Servida',
        self::STATUS_BILLING => 'Pidiendo cuenta',
        self::STATUS_CLOSED => 'Cerrada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    // Sub-estados de delivery (guardados en delivery_metadata.delivery_status)
    public const DELIVERY_PREPARING = 'preparing';
    public const DELIVERY_READY = 'ready';
    public const DELIVERY_ON_THE_WAY = 'on_the_way';
    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_STATUSES = [
        self::DELIVERY_PREPARING => 'Preparando',
        self::DELIVERY_READY => 'Listo para despachar',
        self::DELIVERY_ON_THE_WAY => 'En camino',
        self::DELIVERY_DELIVERED => 'Entregado',
    ];

    public const DELIVERY_STATUS_COLORS = [
        self::DELIVERY_PREPARING => ['bg' => '#fef3c7', 'fg' => '#92400e'],
        self::DELIVERY_READY => ['bg' => '#dbeafe', 'fg' => '#1e40af'],
        self::DELIVERY_ON_THE_WAY => ['bg' => '#e0e7ff', 'fg' => '#3730a3'],
        self::DELIVERY_DELIVERED => ['bg' => '#dcfce7', 'fg' => '#166534'],
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'cash_register_session_id',
        'table_id',
        'zone_id',
        'is_delivery',
        'is_takeaway',
        'server_user_id',
        'third_party_id',
        'prefix',
        'number',
        'tracking_token',
        'guests',
        'status',
        'opened_at',
        'closed_at',
        'cancelled_at',
        'subtotal',
        'discount_total',
        'tax_total',
        'tip_amount',
        'tip_percentage',
        'delivery_fee',
        'total',
        'delivery_metadata',
        'notes',
        'invoice_ids',
        'created_by_user_id',
        'closed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_delivery' => 'boolean',
            'is_takeaway' => 'boolean',
            'guests' => 'integer',
            'number' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'tip_percentage' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_metadata' => 'array',
            'invoice_ids' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(User::class, 'server_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'restaurant_order_id')->orderBy('line_number');
    }

    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class, 'restaurant_order_id')->orderBy('batch_number');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_OPEN, self::STATUS_IN_KITCHEN, self::STATUS_SERVED, self::STATUS_BILLING,
        ], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
