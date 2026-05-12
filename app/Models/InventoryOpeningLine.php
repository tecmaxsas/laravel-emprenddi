<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryOpeningLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_opening_id',
        'line_number',
        'product_id',
        'quantity',
        'unit_cost',
        'inventory_movement_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(InventoryOpening::class, 'inventory_opening_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}
