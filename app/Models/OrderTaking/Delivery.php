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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Cuanto vale lo que se despacho, al precio que quedo pactado en el pedido.
     *
     * Es lo que el cliente deberia estar pagando con este despacho, y sirve de
     * referencia al registrar el abono.
     */
    public function value(): float
    {
        $this->loadMissing('items.orderItem');

        return round($this->items->sum(
            fn (DeliveryItem $item) => (float) $item->quantity_delivered
                * (float) ($item->orderItem?->unit_price_at_public ?? 0)
        ), 2);
    }

    public function paidAmount(): float
    {
        $this->loadMissing('payments');

        return round((float) $this->payments->sum('amount'), 2);
    }

    public function label(): string
    {
        return $this->delivery_number
            ? "Remisión {$this->delivery_number}"
            : 'Despacho #'.$this->id;
    }
}
