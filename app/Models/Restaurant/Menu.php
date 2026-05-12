<?php

namespace App\Models\Restaurant;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_menus';

    public const DEFAULT_THEME = [
        'primary_color' => '#dc2626',
        'accent_color' => '#fbbf24',
        'bg_color' => '#fff7ed',
        'text_color' => '#1c1917',
        'muted_color' => '#78716c',
        'font_family' => 'Playfair Display',
        'layout' => 'list',          // 'list' o 'grid'
        'item_image_size' => 'medium', // 'small', 'medium', 'large'
        'show_descriptions' => true,
        'show_images' => true,
        'header_style' => 'image',   // 'image', 'gradient', 'solid'
    ];

    public const FONT_FAMILIES = [
        'Playfair Display' => 'Playfair Display (elegante serif)',
        'Inter' => 'Inter (limpia sans-serif)',
        'Poppins' => 'Poppins (moderna)',
        'Lora' => 'Lora (serif suave)',
        'Montserrat' => 'Montserrat (geometric)',
        'Bebas Neue' => 'Bebas Neue (condensada)',
        'Pacifico' => 'Pacifico (script)',
        'Cormorant Garamond' => 'Cormorant Garamond (clásica)',
        'Quicksand' => 'Quicksand (redondeada)',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'subtitle',
        'logo_path',
        'header_image_path',
        'theme',
        'show_prices',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'show_prices' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(MenuSection::class, 'restaurant_menu_id')
            ->orderBy('display_order')->orderBy('id');
    }

    public function themeValue(string $key, $fallback = null)
    {
        return data_get($this->theme, $key, $fallback ?? data_get(self::DEFAULT_THEME, $key));
    }
}
