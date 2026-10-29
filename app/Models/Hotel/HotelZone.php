<?php

namespace App\Models\Hotel;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona del hotel: piso, torre, ala, bungalows. Agrupa habitaciones en el
 * mapa de recepción, que es donde el recepcionista trabaja todo el día.
 */
class HotelZone extends Model
{
    use BelongsToCompany;

    protected $table = 'hotel_zones';

    protected $fillable = [
        'company_id',
        'hotel_id',
        'name',
        'code',
        'color',
        'display_order',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'zone_id');
    }
}
