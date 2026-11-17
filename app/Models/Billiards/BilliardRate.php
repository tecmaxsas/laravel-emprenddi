<?php

namespace App\Models\Billiards;

use App\Traits\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tarifa de mesa. El campo `config` es un JSON con las reglas de calculo;
 * el schema completo esta documentado en BilliardRateEngine.
 *
 * Si `table_type_id` es null, la tarifa aplica a cualquier mesa de la sala.
 * Entre varias vigentes gana la de mayor `priority`, y a igual prioridad la
 * mas especifica (la que si nombra el tipo de mesa).
 */
class BilliardRate extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const KIND_REGULAR = 'regular';
    public const KIND_EVENT = 'event';          // torneo, noche de promocion
    public const KIND_CORPORATE = 'corporate';  // convenio con una empresa

    public const KINDS = [
        self::KIND_REGULAR => 'Regular',
        self::KIND_EVENT => 'Evento / torneo',
        self::KIND_CORPORATE => 'Convenio',
    ];

    protected $fillable = [
        'company_id',
        'hall_id',
        'table_type_id',
        'name',
        'kind',
        'config',
        'valid_from',
        'valid_to',
        'priority',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(BilliardHall::class, 'hall_id');
    }

    public function tableType(): BelongsTo
    {
        return $this->belongsTo(BilliardTableType::class, 'table_type_id');
    }

    public function isValidAt(CarbonInterface $date): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->valid_from && $date->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_to && $date->gt($this->valid_to)) {
            return false;
        }

        return true;
    }
}
