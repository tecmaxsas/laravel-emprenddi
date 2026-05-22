<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Movimiento de capital de un socio. El usuario ingresa magnitudes
 * positivas; el tipo de movimiento define el signo de cuotas y valor:
 * cesión entregada y retiro restan capital, el resto suma.
 */
class PartnerMovement extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const TYPE_INITIAL = 'aporte_inicial';
    public const TYPE_ADDITIONAL = 'aporte_adicional';
    public const TYPE_CAPITALIZATION = 'capitalizacion';
    public const TYPE_TRANSFER_IN = 'cesion_ingreso';
    public const TYPE_TRANSFER_OUT = 'cesion_egreso';
    public const TYPE_WITHDRAWAL = 'retiro';

    public const TYPES = [
        self::TYPE_INITIAL => 'Aporte inicial',
        self::TYPE_ADDITIONAL => 'Aporte adicional',
        self::TYPE_CAPITALIZATION => 'Capitalización (utilidades)',
        self::TYPE_TRANSFER_IN => 'Cesión recibida (ingreso)',
        self::TYPE_TRANSFER_OUT => 'Cesión entregada (egreso)',
        self::TYPE_WITHDRAWAL => 'Retiro / reembolso de capital',
    ];

    /** Tipos que reducen el capital del socio (cuotas y valor negativos). */
    public const OUTFLOW_TYPES = [
        self::TYPE_TRANSFER_OUT,
        self::TYPE_WITHDRAWAL,
    ];

    protected $fillable = [
        'company_id',
        'partner_id',
        'date',
        'movement_type',
        'quotas',
        'unit_value',
        'amount',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quotas' => 'decimal:4',
            'unit_value' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // El usuario ingresa magnitudes positivas; el tipo define el signo.
        static::saving(function (PartnerMovement $movement) {
            $isOutflow = in_array($movement->movement_type, self::OUTFLOW_TYPES, true);
            $movement->quotas = $isOutflow ? -abs((float) $movement->quotas) : abs((float) $movement->quotas);
            $movement->amount = $isOutflow ? -abs((float) $movement->amount) : abs((float) $movement->amount);
        });
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isOutflow(): bool
    {
        return in_array($this->movement_type, self::OUTFLOW_TYPES, true);
    }
}
