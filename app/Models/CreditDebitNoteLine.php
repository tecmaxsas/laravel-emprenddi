<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditDebitNoteLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_debit_note_id',
        'line_number',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'cost_at_return',
        'subtotal',
        'discount_percentage',
        'discount_amount',
        'tax_id',
        'tax_rate',
        'tax_amount',
        'total',
        'account_id',
        'inventory_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'cost_at_return' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount_percentage' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(CreditDebitNote::class, 'credit_debit_note_id');
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
}
