<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_invoice_id',
        'line_number',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'cost_at_sale',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'total',
        'account_id',
        'inventory_movement_id',
        'serials',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'cost_at_sale' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'serials' => 'array',
        ];
    }

    public function productSerials(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductSerial::class, 'sale_invoice_line_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class, 'sale_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
