<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota de ajuste de nomina electronica.
 *
 * Es la unica forma de corregir una nomina que la DIAN ya acepto: reenviarla
 * no se puede, porque no admite dos veces el mismo documento. La nota apunta a
 * la anterior por su CUNE y la reemplaza (tipo 1) o la anula (tipo 2).
 */
class PayrollAdjustmentNote extends Model
{
    use BelongsToCompany;

    public const DIAN_PENDING = 'pending';

    public const DIAN_SENT = 'sent';

    public const DIAN_ACCEPTED = 'accepted';

    public const DIAN_REJECTED = 'rejected';

    public const DIAN_STATUSES = [
        self::DIAN_PENDING => 'Pendiente',
        self::DIAN_SENT => 'Enviada',
        self::DIAN_ACCEPTED => 'Aceptada',
        self::DIAN_REJECTED => 'Rechazada',
    ];

    public const TYPES = [
        1 => 'Reemplazar',
        2 => 'Eliminar',
    ];

    protected $fillable = [
        'company_id',
        'payroll_slip_id',
        'type_note',
        'prefix',
        'consecutive',
        'cune',
        'predecessor_prefix',
        'predecessor_consecutive',
        'predecessor_cune',
        'predecessor_issue_date',
        'dian_status',
        'dian_status_code',
        'dian_error_message',
        'payload',
        'dian_response',
        'dian_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type_note' => 'integer',
            'consecutive' => 'integer',
            'predecessor_consecutive' => 'integer',
            'predecessor_issue_date' => 'date',
            'payload' => 'array',
            'dian_response' => 'array',
            'dian_sent_at' => 'datetime',
        ];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.$this->consecutive;
    }
}
