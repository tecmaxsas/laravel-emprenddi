<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cita / agendamiento de un servicio. Pertenece al modulo opcional
 * Citas (AppointmentsSettings). Flujo de estados:
 *
 *   scheduled  → agendada (estado inicial)
 *   confirmed  → confirmada por el cliente
 *   attended   → cliente llego / servicio en curso (habilita cobrar en POS)
 *   completed  → cobrada (sale_invoice_id enlazado)
 *   cancelled  → cancelada
 *   no_show    → no asistio
 */
class Appointment extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_SCHEDULED => 'Agendada',
        self::STATUS_CONFIRMED => 'Confirmada',
        self::STATUS_ATTENDED => 'Atendida',
        self::STATUS_COMPLETED => 'Completada',
        self::STATUS_CANCELLED => 'Cancelada',
        self::STATUS_NO_SHOW => 'No asistió',
    ];

    public const STATUS_COLORS = [
        self::STATUS_SCHEDULED => 'gray',
        self::STATUS_CONFIRMED => 'info',
        self::STATUS_ATTENDED => 'warning',
        self::STATUS_COMPLETED => 'success',
        self::STATUS_CANCELLED => 'danger',
        self::STATUS_NO_SHOW => 'danger',
    ];

    /** Estados que aun ocupan la agenda (no cerrados). */
    public const ACTIVE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_ATTENDED,
    ];

    protected $fillable = [
        'company_id',
        'third_party_id',
        'employee_id',
        'product_id',
        'sale_invoice_id',
        'created_by',
        'title',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'gray';
    }

    public function durationMinutes(): int
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 0;
        }
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Aun se puede operar (cobrar/reprogramar): no cancelada ni completada. */
    public function isOpen(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Lista para cobrar: atendida y sin factura enlazada. */
    public function isBillable(): bool
    {
        return $this->status === self::STATUS_ATTENDED && $this->sale_invoice_id === null;
    }
}
