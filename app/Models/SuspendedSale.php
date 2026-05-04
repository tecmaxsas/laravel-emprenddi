<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspendedSale extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'location_id',
        'seller_user_id',
        'third_party_id',
        'name',
        'cart_snapshot',
        'payments_snapshot',
        'total',
        'items_count',
    ];

    protected function casts(): array
    {
        return [
            'cart_snapshot' => 'array',
            'payments_snapshot' => 'array',
            'total' => 'decimal:2',
            'items_count' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }
}
