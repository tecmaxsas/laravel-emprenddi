<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditDebitNote extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';

    public const TYPES = [
        self::TYPE_CREDIT => 'Nota Crédito',
        self::TYPE_DEBIT => 'Nota Débito',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_POSTED => 'Contabilizada',
        self::STATUS_CANCELLED => 'Anulada',
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

    /**
     * Razones DIAN para Nota Crédito (type_document_id=4).
     * Códigos oficiales del catálogo DIAN.
     */
    public const CREDIT_REASONS = [
        1 => 'Devolución parcial de bienes / no aceptación parcial del servicio',
        2 => 'Anulación de factura electrónica',
        3 => 'Rebaja o descuento parcial o total',
        4 => 'Ajuste de precio',
        5 => 'Otros',
    ];

    /**
     * Razones DIAN para Nota Débito (type_document_id=5).
     */
    public const DEBIT_REASONS = [
        1 => 'Intereses',
        2 => 'Gastos por cobrar',
        3 => 'Cambio del valor',
        4 => 'Otros',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'third_party_id',
        'sale_invoice_id',
        'type',
        'prefix',
        'number',
        'date',
        'reason_code',
        'reason_description',
        'affects_inventory',
        'currency',
        'exchange_rate',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'status',
        'journal_entry_id',
        'created_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'dian_resolution_id',
        'dian_status',
        'dian_status_code',
        'cufe',
        'qr_url',
        'dian_response',
        'dian_error_message',
        'dian_sent_at',
        'description',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
            'dian_sent_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'reason_code' => 'integer',
            'number' => 'integer',
            'affects_inventory' => 'boolean',
            'dian_response' => 'array',
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

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditDebitNoteLine::class)->orderBy('line_number');
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

    public function dianResolution(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Dian\Resolution::class, 'dian_resolution_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isCredit(): bool { return $this->type === self::TYPE_CREDIT; }
    public function isDebit(): bool { return $this->type === self::TYPE_DEBIT; }
    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isPosted(): bool { return $this->status === self::STATUS_POSTED; }
    public function isDianAccepted(): bool { return $this->dian_status === self::DIAN_ACCEPTED; }
    public function canResendToDian(): bool {
        return $this->isPosted() && $this->dian_status !== self::DIAN_ACCEPTED;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function reasonLabel(): string
    {
        $list = $this->isCredit() ? self::CREDIT_REASONS : self::DEBIT_REASONS;
        return $list[$this->reason_code] ?? "Código {$this->reason_code}";
    }

    /**
     * type_document_id DIAN: 4=NC, 5=ND
     */
    public function dianTypeDocumentId(): int
    {
        return $this->isCredit() ? 4 : 5;
    }
}
