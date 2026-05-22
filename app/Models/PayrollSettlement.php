<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liquidación de prestaciones sociales de un empleado.
 */
class PayrollSettlement extends Model
{
    use HasFactory, BelongsToCompany;

    public const TYPE_PRIMA = 'prima';
    public const TYPE_CESANTIAS = 'cesantias';
    public const TYPE_INTERESES = 'intereses_cesantias';
    public const TYPE_VACACIONES = 'vacaciones';
    public const TYPE_DEFINITIVA = 'definitiva';

    public const TYPES = [
        self::TYPE_PRIMA => 'Prima de servicios',
        self::TYPE_CESANTIAS => 'Cesantías',
        self::TYPE_INTERESES => 'Intereses sobre cesantías',
        self::TYPE_VACACIONES => 'Vacaciones',
        self::TYPE_DEFINITIVA => 'Liquidación definitiva',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_PAID => 'Pagada',
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'employment_contract_id',
        'type',
        'period_start',
        'period_end',
        'worked_days',
        'monthly_salary',
        'base',
        'amount_cesantias',
        'amount_interest',
        'amount_prima',
        'amount_vacaciones',
        'amount_indemnizacion',
        'amount',
        'status',
        'payment_date',
        'paid_at',
        'journal_entry_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'worked_days' => 'integer',
            'monthly_salary' => 'decimal:2',
            'base' => 'decimal:2',
            'amount_cesantias' => 'decimal:2',
            'amount_interest' => 'decimal:2',
            'amount_prima' => 'decimal:2',
            'amount_vacaciones' => 'decimal:2',
            'amount_indemnizacion' => 'decimal:2',
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'employment_contract_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
