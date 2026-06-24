<?php

namespace App\Models\Parking;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tarifa de parqueadero. El campo `config` es un JSON con las reglas de
 * calculo (ver ParkingRateEngine para el schema completo). Soporta:
 *
 *  - flat                 monto fijo por sesion
 *  - per_minute           tarifa por minuto con redondeo opcional
 *  - per_hour             tarifa por hora con fraccion proporcional o ceil
 *  - per_day              tarifa 24h
 *  - tiered               escalones: 0-60 min plana, 60-180 por hora, etc.
 *  - free_minutes         minutos gratuitos iniciales (cortesia)
 *  - daily_cap            tope maximo por dia
 *  - night/day            franja horaria diferenciada
 *
 * Diferenciacion por tipo de vehiculo: vehicle_type_id se asocia a la
 * tarifa. Si es null, aplica a cualquier tipo del parqueadero.
 */
class ParkingRate extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const KIND_REGULAR = 'regular';
    public const KIND_MEMBERSHIP = 'membership';     // Mensualidad
    public const KIND_CORPORATE = 'corporate';       // Convenio empresarial
    public const KIND_EVENT = 'event';               // Tarifa de evento
    public const KIND_ACCESSIBILITY = 'accessibility'; // Discapacidad

    public const KINDS = [
        self::KIND_REGULAR => 'Regular',
        self::KIND_MEMBERSHIP => 'Mensualidad',
        self::KIND_CORPORATE => 'Convenio corporativo',
        self::KIND_EVENT => 'Evento',
        self::KIND_ACCESSIBILITY => 'Accesibilidad',
    ];

    protected $fillable = [
        'company_id',
        'parking_lot_id',
        'vehicle_type_id',
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

    public function parkingLot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    /**
     * ¿La tarifa esta vigente a la fecha indicada?
     */
    public function isValidAt(\Carbon\CarbonInterface $date): bool
    {
        if (! $this->active) return false;
        if ($this->valid_from && $date->lt($this->valid_from)) return false;
        if ($this->valid_to && $date->gt($this->valid_to)) return false;
        return true;
    }
}
