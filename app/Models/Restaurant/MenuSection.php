<?php

namespace App\Models\Restaurant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuSection extends Model
{
    use HasFactory;

    protected $table = 'restaurant_menu_sections';

    protected $fillable = [
        'restaurant_menu_id',
        'name',
        'description',
        'display_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'restaurant_menu_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'restaurant_menu_section_id')
            ->orderBy('display_order')->orderBy('id');
    }
}
