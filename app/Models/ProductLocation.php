<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'location_id',
        'active',
        'min_stock',
        'max_stock',
        'reorder_point',
        'override_sale_price',
        'override_purchase_price',
        'shelf_location',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'min_stock' => 'decimal:4',
            'max_stock' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'override_sale_price' => 'decimal:2',
            'override_purchase_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
