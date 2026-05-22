<?php

namespace App\Models;

use App\Services\Payroll\PayrollNoveltyCatalog;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Novedad de nómina cargada para un empleado en un período. El tipo,
 * nombre y carácter salarial se derivan del PayrollNoveltyCatalog.
 */
class PayrollNovelty extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'payroll_period_id',
        'employee_id',
        'concept_code',
        'quantity',
        'amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function conceptName(): string
    {
        return PayrollNoveltyCatalog::name((string) $this->concept_code);
    }

    public function conceptType(): string
    {
        return PayrollNoveltyCatalog::type((string) $this->concept_code);
    }

    public function affectsIbc(): bool
    {
        return PayrollNoveltyCatalog::affectsIbc((string) $this->concept_code);
    }

    public function isEarning(): bool
    {
        return $this->conceptType() === PayrollNoveltyCatalog::TYPE_EARNING;
    }
}
