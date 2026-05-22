<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const KIND_INVOICE = 'invoice';
    public const KIND_SUPPORT_DOCUMENT = 'support_document';

    public const KINDS = [
        self::KIND_INVOICE => 'Factura de compra',
        self::KIND_SUPPORT_DOCUMENT => 'Documento soporte',
    ];

    public const DIAN_PENDING = 'pending';
    public const DIAN_SENT = 'sent';
    public const DIAN_ACCEPTED = 'accepted';
    public const DIAN_REJECTED = 'rejected';

    public const DIAN_STATUSES = [
        self::DIAN_PENDING => 'Pendiente de envío',
        self::DIAN_SENT => 'Enviado',
        self::DIAN_ACCEPTED => 'Aceptado por DIAN',
        self::DIAN_REJECTED => 'Rechazado',
    ];

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

    protected $fillable = [
        'company_id',
        'kind',
        'location_id',
        'cash_register_session_id',
        'third_party_id',
        'prefix',
        'number',
        'dian_resolution_id',
        'supplier_invoice_number',
        'date',
        'due_date',
        'payment_terms_days',
        'currency',
        'exchange_rate',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'paid_amount',
        'payment_status',
        'status',
        'journal_entry_id',
        'created_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'description',
        'notes',
        'dian_status',
        'dian_status_code',
        'dian_sent_at',
        'cufe',
        'qr_url',
        'dian_error_message',
        'dian_response',
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
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'payment_terms_days' => 'integer',
            'number' => 'integer',
            'dian_sent_at' => 'datetime',
            'dian_response' => 'array',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class)->orderBy('line_number');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'paymentable');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function dianResolution(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Dian\Resolution::class, 'dian_resolution_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->total - (float) $this->paid_amount;
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

    public function isSupportDocument(): bool
    {
        return $this->kind === self::KIND_SUPPORT_DOCUMENT;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
