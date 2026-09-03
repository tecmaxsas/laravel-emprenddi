<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Desprendible de pago de un empleado en un período de nómina.
 */
class PayrollSlip extends Model
{
    use HasFactory, BelongsToCompany;

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
        'payroll_period_id',
        'employee_id',
        'employment_contract_id',
        'worked_days',
        'base_salary',
        'salary_earned',
        'transport_allowance',
        'total_earnings',
        'total_deductions',
        'net_pay',
        'employee_health',
        'employee_pension',
        'solidarity_fund',
        'solidarity_sub',
        'employer_health',
        'employer_pension',
        'employer_arl',
        'employer_caja',
        'employer_sena',
        'employer_icbf',
        'prov_cesantias',
        'prov_interest',
        'prov_prima',
        'prov_vacaciones',
    
        'prefix',
        'consecutive',
        'dian_status',
        'dian_status_code',
        'cune',
        'qr_url',
        'dian_response',
        'dian_error_message',
        'dian_needs_adjustment',
        'dian_sent_at',
];

    protected function casts(): array
    {
        return [
            'dian_needs_adjustment' => 'boolean',
            'dian_response' => 'array',
            'dian_sent_at' => 'datetime',
            'worked_days' => 'integer',
            'base_salary' => 'decimal:2',
            'salary_earned' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'employee_health' => 'decimal:2',
            'employee_pension' => 'decimal:2',
            'solidarity_fund' => 'decimal:2',
            'solidarity_sub' => 'decimal:2',
            'employer_health' => 'decimal:2',
            'employer_pension' => 'decimal:2',
            'employer_arl' => 'decimal:2',
            'employer_caja' => 'decimal:2',
            'employer_sena' => 'decimal:2',
            'employer_icbf' => 'decimal:2',
            'prov_cesantias' => 'decimal:2',
            'prov_interest' => 'decimal:2',
            'prov_prima' => 'decimal:2',
            'prov_vacaciones' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fullNumber(): string
    {
        return $this->prefix && $this->consecutive
            ? $this->prefix.'-'.$this->consecutive
            : '—';
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'employment_contract_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollSlipLine::class);
    }

    /** Costo total para el empleador = devengado + aportes + provisiones. */
    public function employerCost(): float
    {
        return round(
            (float) $this->total_earnings
            + (float) $this->employer_health + (float) $this->employer_pension
            + (float) $this->employer_arl + (float) $this->employer_caja
            + (float) $this->employer_sena + (float) $this->employer_icbf
            + (float) $this->prov_cesantias + (float) $this->prov_interest
            + (float) $this->prov_prima + (float) $this->prov_vacaciones,
            2,
        );
    }
}
