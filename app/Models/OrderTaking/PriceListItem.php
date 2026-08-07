<?php

namespace App\Models\OrderTaking;

use App\Models\Product;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_price_list_items';

    protected $fillable = [
        'company_id', 'price_list_id', 'product_id',
        'price_before_tax', 'tax_amount', 'price_at_public',
    ];

    protected function casts(): array
    {
        return [
            'price_before_tax' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'price_at_public' => 'decimal:2',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
