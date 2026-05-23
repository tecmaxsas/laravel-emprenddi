<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empleado de la empresa. Los datos contractuales (cargo, salario,
 * vigencia) viven en EmploymentContract — un empleado puede tener varios
 * contratos a lo largo del tiempo, pero solo uno activo.
 */
class Employee extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Activo',
        self::STATUS_INACTIVE => 'Inactivo',
    ];

    public const GENDERS = [
        'M' => 'Masculino',
        'F' => 'Femenino',
    ];

    public const PAYMENT_METHODS = [
        'deposito' => 'Depósito en cuenta',
        'efectivo' => 'Efectivo',
        'cheque' => 'Cheque',
    ];

    public const BANK_ACCOUNT_TYPES = [
        'ahorros' => 'Ahorros',
        'corriente' => 'Corriente',
    ];

    protected $fillable = [
        'company_id',
        'third_party_id',
        'document_type',
        'document_number',
        'first_name',
        'middle_name',
        'last_name',
        'second_last_name',
        'birth_date',
        'gender',
        'email',
        'phone',
        'address',
        'city',
        'payment_method',
        'bank_name',
        'bank_account_type',
        'bank_account_number',
        'eps_name',
        'pension_fund_name',
        'arl_name',
        'compensation_fund_name',
        'housing_interest_deduction',
        'prepaid_health_deduction',
        'has_dependents',
        'hire_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'housing_interest_deduction' => 'decimal:2',
            'prepaid_health_deduction' => 'decimal:2',
            'has_dependents' => 'boolean',
        ];
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class)->orderByDesc('start_date');
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(EmploymentContract::class)
            ->where('status', EmploymentContract::STATUS_ACTIVE);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->second_last_name,
        ])));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
