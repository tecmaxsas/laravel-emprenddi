<?php

namespace App\Models\OrderTaking;

use App\Models\Location;
use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'order_taking_orders';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PARTIAL_DELIVERED = 'partial_delivered';
    public const STATUS_FULLY_DELIVERED = 'fully_delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_CONFIRMED => 'Confirmado',
        self::STATUS_PARTIAL_DELIVERED => 'Entrega parcial',
        self::STATUS_FULLY_DELIVERED => 'Entregado',
        self::STATUS_CANCELLED => 'Anulado',
    ];

    public const DELIVERY_STATUSES = [
        'pending' => 'Pendiente',
        'partial' => 'Parcial',
        'delivered' => 'Entregado',
    ];

    public const PAYMENT_STATUSES = [
        'pendiente' => 'Pendiente',
        'parcial' => 'Parcial',
        'pagado' => 'Pagado',
    ];

    protected $fillable = [
        'company_id', 'prefix', 'number',
        'third_party_id', 'price_list_id', 'location_id',
        'seller_user_id', 'created_by_user_id',
        'order_date', 'delivery_date_expected',
        'status', 'delivery_status', 'payment_status',
        'subtotal', 'tax_total', 'total',
        'paid_amount', 'balance', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'delivery_date_expected' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
