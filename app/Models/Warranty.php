<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ticket de garantía / RMA. Estados:
 *   received   → equipo en la sede, sin revisión todavía
 *   in_review  → técnico está diagnosticando
 *   in_repair  → técnico reparando (o esperando repuesto)
 *   resolved   → reparada y lista para entregar
 *   replaced   → no se reparó, se entregó un nuevo equipo
 *   rejected   → fuera de garantía (rotura por mal uso, expiró, etc.)
 *   delivered  → terminal: cliente recogió el equipo
 *
 * El estado se gobierna por WarrantyEngine, que valida transiciones
 * permitidas y graba el evento en warranty_events.
 */
class Warranty extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_IN_REPAIR = 'in_repair';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUSES = [
        self::STATUS_RECEIVED => 'Recibida',
        self::STATUS_IN_REVIEW => 'En revisión',
        self::STATUS_IN_REPAIR => 'En reparación',
        self::STATUS_RESOLVED => 'Resuelta',
        self::STATUS_REPLACED => 'Reemplazada',
        self::STATUS_REJECTED => 'Rechazada',
        self::STATUS_DELIVERED => 'Entregada',
    ];

    /**
     * Estados que ya no admiten cambios (terminal del flujo).
     */
    public const STATUSES_TERMINAL = [
        self::STATUS_DELIVERED,
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'prefix',
        'number',
        'rma_number',
        'third_party_id',
        'product_id',
        'product_serial_id',
        'sale_invoice_id',
        'claim_date',
        'expiration_date',
        'resolved_at',
        'delivered_at',
        'status',
        'assigned_user_id',
        'received_by_user_id',
        'reason',
        'resolution_notes',
        'attachments',
    ];

    protected $casts = [
        'claim_date' => 'date',
        'expiration_date' => 'date',
        'resolved_at' => 'datetime',
        'delivered_at' => 'datetime',
        'attachments' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WarrantyEvent::class)->orderBy('created_at');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::STATUSES_TERMINAL, true);
    }

    public function isExpired(): bool
    {
        return $this->expiration_date !== null
            && $this->expiration_date->isPast();
    }
}
