<?php

namespace App\Models\OrderTaking;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryItem extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_delivery_items';

    protected $fillable = [
        'company_id', 'delivery_id', 'order_item_id', 'quantity_delivered',
    ];

    protected function casts(): array
    {
        return ['quantity_delivered' => 'decimal:3'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
