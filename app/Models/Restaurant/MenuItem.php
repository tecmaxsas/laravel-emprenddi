<?php

namespace App\Models\Restaurant;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    use HasFactory;

    protected $table = 'restaurant_menu_items';

    public const COMMON_TAGS = [
        'new' => '✨ Nuevo',
        'popular' => '🔥 Popular',
        'vegan' => '🌱 Vegano',
        'vegetarian' => '🥗 Vegetariano',
        'gluten_free' => '🌾 Sin gluten',
        'spicy' => '🌶️ Picante',
        'house_special' => '⭐ Especial de la casa',
    ];

    protected $fillable = [
        'restaurant_menu_section_id',
        'product_id',
        'name',
        'description',
        'price',
        'image_path',
        'tags',
        'display_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tags' => 'array',
            'display_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'restaurant_menu_section_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
