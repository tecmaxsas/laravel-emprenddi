<?php

namespace App\Models\Hotel;

use App\Models\Product;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plan de alimentación: lo que el huésped come además de dormir.
 *
 * Los códigos son los de la hotelería y conviene usarlos porque son los que el
 * cliente ya conoce de Booking y de las agencias.
 */
class MealPlan extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_meal_plans';

    /**
     * Los planes canónicos del oficio, para sembrarlos y para que el
     * desplegable no obligue a cada hotel a inventarse los nombres.
     */
    public const CANONICOS = [
        ['code' => 'EP', 'name' => 'Solo alojamiento', 'sort_order' => 10,
            'description' => 'Sin alimentación incluida.'],
        ['code' => 'CP', 'name' => 'Alojamiento + desayuno', 'sort_order' => 20,
            'description' => 'Incluye el desayuno de cada mañana de la estadía.'],
        ['code' => 'MAP', 'name' => 'Media pensión', 'sort_order' => 30,
            'description' => 'Desayuno y una comida principal.'],
        ['code' => 'AP', 'name' => 'Pensión completa', 'sort_order' => 40,
            'description' => 'Desayuno, almuerzo y cena.'],
        ['code' => 'AI', 'name' => 'Todo incluido', 'sort_order' => 50,
            'description' => 'Alimentación y bebidas incluidas.'],
    ];

    protected $fillable = [
        'company_id',
        'hotel_id',
        'code',
        'name',
        'description',
        'included_in_rate',
        'price_per_adult',
        'price_per_child',
        'product_id',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'included_in_rate' => 'boolean',
            'price_per_adult' => 'decimal:2',
            'price_per_child' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Lo que cuesta una noche de este plan para esa gente.
     *
     * Cero si la tarifa ya lo incluye: ahí el plan no es un cobro, es una
     * aclaración de qué cubre lo que ya se está pagando.
     */
    public function porNoche(int $adultos, int $ninos = 0): float
    {
        if ($this->included_in_rate) {
            return 0.0;
        }

        return round(
            max(0, $adultos) * (float) $this->price_per_adult
            + max(0, $ninos) * (float) $this->price_per_child,
            2,
        );
    }

    /** Si genera renglón en el folio o solo se informa. */
    public function seCobra(): bool
    {
        return ! $this->included_in_rate
            && ((float) $this->price_per_adult > 0 || (float) $this->price_per_child > 0);
    }

    public function etiqueta(): string
    {
        return $this->code.' · '.$this->name;
    }
}
