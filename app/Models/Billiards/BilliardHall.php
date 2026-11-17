<?php

namespace App\Models\Billiards;

use App\Models\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sala de billar. Separada de Sedes porque un negocio puede tener dos
 * salones en la misma sede (el de abajo y el del segundo piso) con mesas,
 * tarifas y mapas distintos.
 */
class BilliardHall extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'city',
        'phone',
        'default_location_id',
        'default_invoice_kind',
        'settings',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'active' => 'boolean',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(BilliardTable::class, 'hall_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(BilliardRate::class, 'hall_id');
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }
}
