<?php

namespace App\Models\Restaurant;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenTicket extends Model
{
    use HasFactory;

    protected $table = 'restaurant_kitchen_tickets';

    protected $fillable = [
        'restaurant_order_id',
        'restaurant_printer_id',
        'batch_number',
        'items_snapshot',
        'status',
        'error_message',
        'printed_by_user_id',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'batch_number' => 'integer',
            'items_snapshot' => 'array',
            'printed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'restaurant_order_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'restaurant_printer_id');
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by_user_id');
    }
}
