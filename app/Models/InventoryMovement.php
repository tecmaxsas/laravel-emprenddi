<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    use HasFactory, BelongsToCompany;

    public const TYPES = [
        'opening' => 'Saldo inicial',
        'purchase' => 'Compra',
        'sale' => 'Venta',
        'delivery_out' => 'Despacho remisión',
        'adjustment_in' => 'Ajuste entrada',
        'adjustment_out' => 'Ajuste salida',
        'transfer_in' => 'Traslado entrada',
        'transfer_out' => 'Traslado salida',
        'return_to_supplier' => 'Devolución a proveedor',
        'return_from_customer' => 'Devolución de cliente',
    ];

    public const ENTRY_TYPES = ['opening', 'purchase', 'adjustment_in', 'transfer_in', 'return_from_customer'];
    public const EXIT_TYPES = ['sale', 'delivery_out', 'adjustment_out', 'transfer_out', 'return_to_supplier'];

    protected $fillable = [
        'company_id',
        'product_id',
        'location_id',
        'date',
        'type',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_quantity_after',
        'balance_cost_after',
        'balance_value_after',
        'remaining_quantity',
        'reference_type',
        'reference_id',
        'reference_number',
        'third_party_id',
        'journal_entry_id',
        'created_by_user_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'balance_quantity_after' => 'decimal:4',
            'balance_cost_after' => 'decimal:4',
            'balance_value_after' => 'decimal:2',
            'remaining_quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isEntry(): bool
    {
        return in_array($this->type, self::ENTRY_TYPES, true);
    }

    public function isExit(): bool
    {
        return in_array($this->type, self::EXIT_TYPES, true);
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryMovement $movement) {
            \App\Services\Accounting\FiscalPeriodGuard::ensureOpen(
                $movement->company_id,
                $movement->date,
            );
        });
    }
}
