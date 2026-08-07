<?php

namespace App\Models\OrderTaking;

use App\Models\Product;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_order_items';

    protected $fillable = [
        'company_id', 'order_id', 'product_id',
        'line_number', 'description',
        'quantity_ordered', 'quantity_delivered',
        'unit_price_before_tax', 'tax_rate', 'tax_amount',
        'unit_price_at_public', 'subtotal', 'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_delivered' => 'decimal:3',
            'unit_price_before_tax' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'unit_price_at_public' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function pendingQuantity(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_delivered);
    }
}
