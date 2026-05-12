<?php

namespace App\Models\Restaurant;

use App\Models\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceZone extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_service_zones';

    protected $fillable = [
        'company_id',
        'location_id',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class, 'zone_id')->orderBy('code');
    }
}
