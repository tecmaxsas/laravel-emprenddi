<?php

namespace App\Models\Restaurant;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_drivers';

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'license_plate',
        'vehicle_type',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function fullLabel(): string
    {
        $extras = array_filter([
            $this->license_plate,
            $this->phone,
        ]);

        return $extras
            ? $this->name.' ('.implode(' · ', $extras).')'
            : $this->name;
    }
}
