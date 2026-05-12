<?php

namespace App\Models\Restaurant;

use App\Models\Product;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'restaurant_order_items';

    public const KS_PENDING = 'pending';
    public const KS_SENT = 'sent';
    public const KS_PREPARING = 'preparing';
    public const KS_READY = 'ready';
    public const KS_SERVED = 'served';
    public const KS_CANCELLED = 'cancelled';

    public const KITCHEN_STATUSES = [
        self::KS_PENDING => 'Pendiente de enviar',
        self::KS_SENT => 'Enviado a cocina',
        self::KS_PREPARING => 'Preparando',
        self::KS_READY => 'Listo',
        self::KS_SERVED => 'Servido',
        self::KS_CANCELLED => 'Cancelado',
    ];

    protected $fillable = [
        'restaurant_order_id',
        'line_number',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'total',
        'modifiers',
        'item_note',
        'kitchen_status',
        'course',
        'sent_to_kitchen_at',
        'preparing_at',
        'ready_at',
        'served_at',
        'cancelled_at',
        'cancel_reason',
        'printed_to_printer_id',
        'kot_batch',
        'split_tab',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'modifiers' => 'array',
            'course' => 'integer',
            'kot_batch' => 'integer',
            'sent_to_kitchen_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'restaurant_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printed_to_printer_id');
    }
}
