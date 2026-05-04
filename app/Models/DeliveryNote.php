<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryNote extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_BILLED = 'billed';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_DISPATCHED => 'Despachada',
        self::STATUS_DELIVERED => 'Entregada',
        self::STATUS_CANCELLED => 'Cancelada',
        self::STATUS_BILLED => 'Facturada',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'third_party_id',
        'prefix',
        'number',
        'date',
        'carrier',
        'vehicle_plate',
        'driver_name',
        'destination_address',
        'status',
        'dispatched_at',
        'dispatched_by_user_id',
        'delivered_at',
        'billed_at_sale_invoice_id',
        'billed_at',
        'created_by_user_id',
        'seller_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'billed_at' => 'datetime',
            'number' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function billedAtSaleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'billed_at_sale_invoice_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isDispatched(): bool { return $this->status === self::STATUS_DISPATCHED; }
    public function isDelivered(): bool { return $this->status === self::STATUS_DELIVERED; }
    public function isBilled(): bool { return $this->status === self::STATUS_BILLED; }

    public function canBeDispatched(): bool { return $this->isDraft(); }
    public function canBeBilled(): bool {
        return in_array($this->status, [self::STATUS_DISPATCHED, self::STATUS_DELIVERED], true);
    }
}
