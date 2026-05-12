<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_return_id',
        'line_number',
        'product_id',
        'description',
        'quantity',
        'unit_cost',
        'subtotal',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'total',
        'source_purchase_line_id',
        'inventory_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function sourcePurchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'source_purchase_line_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}
