<?php

namespace App\Models\OrderTaking;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_deliveries';

    protected $fillable = [
        'company_id', 'order_id', 'delivery_number',
        'delivery_date', 'delivered_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['delivery_date' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }
}
