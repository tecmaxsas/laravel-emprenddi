<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_transfer_id',
        'line_number',
        'product_id',
        'quantity',
        'unit_cost',
        'out_movement_id',
        'in_movement_id',
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

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'out_movement_id');
    }

    public function inMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'in_movement_id');
    }
}
