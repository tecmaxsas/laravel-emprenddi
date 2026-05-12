<?php

namespace App\Models\Restaurant;

use App\Models\Location;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Table extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'restaurant_tables';

    public const STATUSES = [
        'free' => 'Libre',
        'occupied' => 'Ocupada',
        'reserved' => 'Reservada',
        'billing' => 'Pidiendo cuenta',
        'cleaning' => 'Limpieza',
    ];

    public const SHAPES = [
        'square' => 'Cuadrada',
        'round' => 'Redonda',
        'rect' => 'Rectangular',
        'bar' => 'Barra',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'zone_id',
        'code',
        'label',
        'capacity',
        'shape',
        'pos_x',
        'pos_y',
        'width',
        'height',
        'status',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    public function activeOrder(): ?Order
    {
        return $this->orders()
            ->whereIn('status', ['open', 'in_kitchen', 'served', 'billing'])
            ->latest('opened_at')
            ->first();
    }

    public function isFree(): bool
    {
        return $this->status === 'free';
    }

    public function isOccupied(): bool
    {
        return in_array($this->status, ['occupied', 'billing'], true);
    }
}
