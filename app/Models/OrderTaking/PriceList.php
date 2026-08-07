<?php

namespace App\Models\OrderTaking;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceList extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'order_taking_price_lists';

    protected $fillable = [
        'company_id', 'code', 'name',
        'valid_from', 'valid_to', 'active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}
