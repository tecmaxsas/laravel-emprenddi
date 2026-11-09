<?php

namespace App\Models;

use App\Models\Dian\Resolution;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleInvoice extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

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
        'third_party_branch_id',
        'prefix',
        'number',
        'invoice_kind',
        'origin',
        'date',
        'due_date',
        'payment_terms_days',
        'currency',
        'exchange_rate',
        'subtotal',
        'discount_total',
        'global_discount_type',
        'global_discount_value',
        'global_discount_amount',
        'tax_total',
        'retention_total',
        'total',
        'net_payable',
        'tip_amount',
        'paid_amount',
        'credited_amount',
        'payment_status',
        'status',
        'journal_entry_id',
        'created_by_user_id',
        'posted_by_user_id',
        'seller_user_id',
        'cash_register_session_id',
        'from_delivery_note_id',
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
            'tip_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'payment_terms_days' => 'integer',
            'number' => 'integer',
            'dian_response' => 'array',
            'dian_sent_at' => 'datetime',
        ];
    }

    public function dianResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'dian_resolution_id');
    }

    public function isDianAccepted(): bool
    {
        return $this->dian_status === self::DIAN_ACCEPTED;
    }

    /**
     * Las facturas POS NO se transmiten a DIAN — solo las electrónicas.
     * invoice_kind null se trata como electrónica (facturas legacy).
     */
    public function isPosInvoice(): bool
    {
        return $this->invoice_kind === 'pos';
    }

    /**
     * De que pantalla nacio la factura.
     *
     * Distinto de `invoice_kind`, que dice que CLASE de documento es. Una
     * venta del POS puede ser electronica y una del formulario puede ser un
     * tiquete POS; confundir las dos preguntas manda al usuario a corregir su
     * venta en una pantalla que no es la suya.
     */
    public const ORIGIN_POS = 'pos';

    public const ORIGIN_FORM = 'form';

    public const ORIGIN_RESTAURANT = 'restaurant';

    public const ORIGIN_PARKING = 'parking';

    public const ORIGIN_HOTEL = 'hotel';

    public const ORIGIN_ORDER_TAKING = 'order_taking';

    public const ORIGIN_DELIVERY_NOTE = 'delivery_note';

    public const ORIGIN_QUOTATION = 'quotation';

    public const ORIGINS = [
        self::ORIGIN_POS => 'POS',
        self::ORIGIN_FORM => 'Facturación',
        self::ORIGIN_RESTAURANT => 'Restaurante',
        self::ORIGIN_PARKING => 'Parqueadero',
        self::ORIGIN_HOTEL => 'Hotel',
        self::ORIGIN_ORDER_TAKING => 'Toma de pedidos',
        self::ORIGIN_DELIVERY_NOTE => 'Remisión',
        self::ORIGIN_QUOTATION => 'Cotización',
    ];

    /**
     * ¿Se corrige en el POS?
     *
     * Solo las que nacieron ahi. Las de restaurante, parqueadero y hotel
     * tienen sus propias pantallas y su propio flujo; mandarlas al POS de
     * retail seria tan equivocado como mandar una del POS al formulario.
     */
    public function naceEnElPos(): bool
    {
        return $this->origin === self::ORIGIN_POS;
    }

    public function etiquetaDelOrigen(): string
    {
        return self::ORIGINS[$this->origin] ?? 'Facturación';
    }

    public function canResendToDian(): bool
    {
        if ($this->isPosInvoice()) {
            return false;
        }

        if (! $this->isPosted() || $this->dian_status === self::DIAN_ACCEPTED) {
            return false;
        }

        // Enviada y con CUFE: el documento ya viajó firmado y puede estar
        // radicado en la DIAN aunque no tengamos su respuesta. Reenviarlo lo
        // duplica; lo que corresponde es «Consultar estado DIAN», que existe
        // precisamente para esto. Sin CUFE sí se reintenta: ahí no viajó nada.
        if ($this->dian_status === self::DIAN_SENT && ! empty($this->cufe)) {
            return false;
        }

        return true;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    /**
     * La sucursal del cliente a la que se entregó, si la hay.
     *
     * No cambia a quién se factura —eso es el NIT del tercero y lo valida la
     * DIAN—: dice a dónde va la mercancía y permite abrir la cartera por
     * sucursal.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyBranch::class, 'third_party_branch_id');
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

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function fromDeliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'from_delivery_note_id');
    }

    /**
     * Numero visible del documento.
     *
     * En una factura electronica es el identificador con el que el documento
     * queda registrado ante DIAN: prefijo y consecutivo pegados, sin guion ni
     * ceros de relleno (FETR6002). La representacion grafica que se le entrega
     * al cliente tiene que mostrar exactamente ese numero, y es tambien el que
     * viaja en el billing_reference de las notas credito/debito.
     *
     * Las facturas POS no se transmiten a DIAN, asi que conservan el formato
     * legible de siempre (ME-000123).
     */
    public function fullNumber(): string
    {
        if ($this->isPosInvoice()) {
            return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
        }

        return $this->prefix.$this->number;
    }

    /**
     * El saldo pendiente se calcula contra net_payable (total - retenciones),
     * no contra total: las retenciones son anticipos de impuesto, no quedan
     * pendientes de cobro al cliente.
     */
    public function getBalanceAttribute(): float
    {
        $netPayable = (float) ($this->net_payable ?: $this->total);

        // Las notas credito contabilizadas contra esta factura descuentan
        // igual que un abono. Antes no lo hacian: el asiento acreditaba la
        // CxC pero el saldo seguia completo, asi que el cliente devolvia la
        // mercancia y la cartera seguia cobrandosela. Los libros decian una
        // cosa y el reporte de cobro otra, y el que manda al cobrador es el
        // reporte.
        return $netPayable - (float) $this->paid_amount - (float) $this->credited_amount;
    }

    /** Lo que todavia se le puede acreditar a esta factura. */
    public function creditableRemaining(): float
    {
        $netPayable = (float) ($this->net_payable ?: $this->total);

        return round(max(0, $netPayable - (float) $this->credited_amount), 2);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditDebitNote::class, 'sale_invoice_id');
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
