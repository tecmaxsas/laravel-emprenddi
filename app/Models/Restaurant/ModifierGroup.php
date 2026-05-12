<?php

namespace App\Models\Restaurant;

use App\Models\Product;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_modifier_groups';

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'min_select',
        'max_select',
        'required',
        'display_order',
        'active',
    ];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
        'required' => 'boolean',
        'display_order' => 'integer',
        'active' => 'boolean',
    ];

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'restaurant_modifier_group_id')
            ->orderBy('display_order')
            ->orderBy('name');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_restaurant_modifier_group',
            'restaurant_modifier_group_id',
            'product_id',
        )->withPivot('display_order');
    }

    /**
     * Cómo se renderiza en POS: radio (1 opción) o checkbox (varias).
     */
    public function isSingleSelect(): bool
    {
        return $this->max_select <= 1;
    }
}
