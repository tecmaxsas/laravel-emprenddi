<?php

namespace App\Models\Parking;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de vehiculo que el parqueadero atiende: carro, moto, bici, camion...
 * Catalogo por empresa. Permite tarifas distintas por tipo.
 */
class VehicleType extends Model
{
    use BelongsToCompany;

    public const CODE_CAR = 'CAR';
    public const CODE_MOTORCYCLE = 'MOTO';
    public const CODE_BICYCLE = 'BIKE';
    public const CODE_TRUCK = 'TRUCK';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'icon',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ParkingRate::class);
    }
}
