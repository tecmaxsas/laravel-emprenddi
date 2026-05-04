<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleInvoice extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_POSTED => 'Contabilizada',
        self::STATUS_CANCELLED => 'Anulada',
    ];

    public const PAYMENT_PENDIENTE = 'pendiente';
    public const PAYMENT_PARCIAL = 'parcial';
    public const PAYMENT_PAGADO = 'pagado';
    public const PAYMENT_VENCIDO = 'vencido';
    public const PAYMENT_CANCELADA = 'cancelada';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_PENDIENTE => 'Pendiente',
        self::PAYMENT_PARCIAL => 'Parcial',
        self::PAYMENT_PAGADO => 'Pagado',
        self::PAYMENT_VENCIDO => 'Vencido',
        self::PAYMENT_CANCELADA => 'Cancelada',
    ];

    public const DIAN_PENDING = 'pending';
    public const DIAN_SENT = 'sent';
    public const DIAN_ACCEPTED = 'accepted';
    public const DIAN_REJECTED = 'rejected';

    public const DIAN_STATUSES = [
        self::DIAN_PENDING => 'Pendiente DIAN',
        self::DIAN_SENT => 'Enviado',
        self::DIAN_ACCEPTED => 'Aceptado DIAN',
        self::DIAN_REJECTED => 'Rechazado DIAN',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'third_party_id',
        'prefix',
        'number',
        'date',
        'due_date',
        'payment_terms_days',
        'currency',
        'exchange_rate',
        'subtotal',
        'discount_total',
        'tax_total',
        'retention_total',
        'total',
        'net_payable',
        'paid_amount',
        'payment_status',
        'status',
        'journal_entry_id',
        'created_by_user_id',
        'posted_by_user_id',
        'seller_user_id',
        'posted_at',
        'description',
        'notes',
        // DIAN
        'dian_resolution_id',
        'dian_status',
        'dian_status_code',
        'cufe',
        'qr_url',
        'dian_response',
        'dian_error_message',
        'dian_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'retention_total' => 'decimal:2',
            'total' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'payment_terms_days' => 'integer',
            'number' => 'integer',
            'dian_response' => 'array',
            'dian_sent_at' => 'datetime',
        ];
    }

    public function dianResolution(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Dian\Resolution::class, 'dian_resolution_id');
    }

    public function isDianAccepted(): bool
    {
        return $this->dian_status === self::DIAN_ACCEPTED;
    }

    public function canResendToDian(): bool
    {
        return $this->isPosted() && $this->dian_status !== self::DIAN_ACCEPTED;
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
        return $this->hasMany(SaleInvoiceLine::class)->orderBy('line_number');
    }

    public function retentions(): HasMany
    {
        return $this->hasMany(SaleInvoiceRetention::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * El saldo pendiente se calcula contra net_payable (total - retenciones),
     * no contra total: las retenciones son anticipos de impuesto, no quedan
     * pendientes de cobro al cliente.
     */
    public function getBalanceAttribute(): float
    {
        $netPayable = (float) ($this->net_payable ?: $this->total);

        return $netPayable - (float) $this->paid_amount;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFullyPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAGADO;
    }
}
