<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contrato laboral de un empleado. El contrato activo define el cargo,
 * el salario y las condiciones vigentes sobre las que se liquida nómina.
 */
class EmploymentContract extends Model
{
    use HasFactory, BelongsToCompany;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TERMINATED = 'terminated';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Vigente',
        self::STATUS_TERMINATED => 'Terminado',
    ];

    public const CONTRACT_TYPES = [
        'indefinido' => 'Término indefinido',
        'fijo' => 'Término fijo',
        'obra_labor' => 'Obra o labor',
        'aprendizaje' => 'Contrato de aprendizaje',
        'practicas' => 'Prácticas / pasantía',
    ];

    public const SALARY_TYPES = [
        'ordinario' => 'Salario ordinario',
        'integral' => 'Salario integral',
    ];

    public const PAYMENT_FREQUENCIES = [
        'mensual' => 'Mensual',
        'quincenal' => 'Quincenal',
    ];

    public const RISK_CLASSES = [
        'I' => 'I — Riesgo mínimo',
        'II' => 'II — Riesgo bajo',
        'III' => 'III — Riesgo medio',
        'IV' => 'IV — Riesgo alto',
        'V' => 'V — Riesgo máximo',
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'contract_type',
        'salary_type',
        'position',
        'salary',
        'transport_allowance_applies',
        'payment_frequency',
        'start_date',
        'end_date',
        'work_location_id',
        'risk_class',
        'status',
        'termination_date',
        'termination_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'transport_allowance_applies' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'termination_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'work_location_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
