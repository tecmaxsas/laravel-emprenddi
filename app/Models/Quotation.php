<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_SENT => 'Enviada',
        self::STATUS_APPROVED => 'Aprobada',
        self::STATUS_REJECTED => 'Rechazada',
        self::STATUS_EXPIRED => 'Vencida',
        self::STATUS_CONVERTED => 'Convertida',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'third_party_id',
        'prefix',
        'number',
        'date',
        'valid_until',
        'currency',
        'exchange_rate',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'status',
        'converted_to_sale_invoice_id',
        'converted_at',
        'created_by_user_id',
        'seller_user_id',
        'terms_and_conditions',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valid_until' => 'date',
            'converted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
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
        return $this->hasMany(QuotationLine::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'converted_to_sale_invoice_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isSent(): bool { return $this->status === self::STATUS_SENT; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isConverted(): bool { return $this->status === self::STATUS_CONVERTED; }

    /**
     * Vencida si la fecha de validez ya pasó y no fue aprobada/convertida/rechazada.
     */
    public function isEffectivelyExpired(): bool
    {
        return $this->valid_until
            && $this->valid_until->isPast()
            && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    public function canBeConverted(): bool
    {
        return ! $this->isConverted() && in_array($this->status, [self::STATUS_SENT, self::STATUS_APPROVED], true);
    }
}
