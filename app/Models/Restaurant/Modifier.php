<?php

namespace App\Models\Restaurant;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Modifier extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_modifiers';

    protected $fillable = [
        'company_id',
        'restaurant_modifier_group_id',
        'name',
        'price_delta',
        'display_order',
        'active',
    ];

    protected $casts = [
        'price_delta' => 'decimal:2',
        'display_order' => 'integer',
        'active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'restaurant_modifier_group_id');
    }
}
