<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de un desprendible: un devengado o una deducción.
 */
class PayrollSlipLine extends Model
{
    use HasFactory;

    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = [
        'payroll_slip_id',
        'type',
        'concept_code',
        'concept_name',
        'quantity',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }

    public function isEarning(): bool
    {
        return $this->type === self::TYPE_EARNING;
    }
}
