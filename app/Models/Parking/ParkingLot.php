<?php

namespace App\Models\Parking;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Parqueadero fisico. Independiente de Sedes/Locations (un parqueadero
 * puede operarse en una ubicacion sin tener que ser una "tienda").
 */
class ParkingLot extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'city',
        'phone',
        'total_capacity',
        'settings',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'total_capacity' => 'integer',
            'settings' => 'array',
            'active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ParkingRate::class);
    }

    public function activeRates(): HasMany
    {
        return $this->hasMany(ParkingRate::class)
            ->where('active', true);
    }
}
