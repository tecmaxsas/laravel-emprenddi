<?php

namespace App\Models\Hotel;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tipo de habitación: Sencilla, Doble, Suite.
 *
 * Aquí vive la tarifa. En un hotel el precio es del tipo y no de la
 * habitación: la 101 y la 102 son dos dobles y valen lo mismo. Ponerlo en
 * cada habitación obligaría a editar cincuenta filas cuando entra temporada
 * alta, y bastaría olvidar una para vender una noche al precio del año
 * pasado.
 */
class RoomType extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_room_types';

    protected $fillable = [
        'company_id',
        'hotel_id',
        'code',
        'name',
        'description',
        'max_adults',
        'max_children',
        'base_rate',
        'extra_person_rate',
        'beds',
        'amenities',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'base_rate' => 'decimal:2',
            'extra_person_rate' => 'decimal:2',
            'amenities' => 'array',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'room_type_id');
    }

    /**
     * Cuánto cuesta la noche para esta cantidad de personas.
     *
     * La tarifa base cubre hasta `max_adults`; cada persona de más suma
     * `extra_person_rate`. Los niños no se cuentan como persona extra: casi
     * ningún hotel del país los cobra, y el que sí quiere hacerlo lo deja
     * dicho subiendo la tarifa del tipo.
     */
    public function tarifaPara(int $adultos): float
    {
        $base = (float) $this->base_rate;
        $extras = max(0, $adultos - max(1, (int) $this->max_adults));

        return round($base + $extras * (float) $this->extra_person_rate, 2);
    }

    public function capacidad(): int
    {
        return (int) $this->max_adults + (int) $this->max_children;
    }
}
