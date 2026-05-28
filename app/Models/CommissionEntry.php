<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comision causada por una venta para un vendedor. Se crea cuando se
 * cumple la condicion de causacion (al facturar o al cobrar, segun
 * CommissionsSettings). Mientras esta 'pending' puede liquidarse; al
 * liquidarse pasa a 'settled' y queda ligada a una CommissionSettlement.
 */
class CommissionEntry extends Model
{
    use HasFactory, BelongsToCompany;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_REVERSED = 'reversed';

    public const BASIS_INVOICED = 'invoiced';
    public const BASIS_COLLECTED = 'collected';

    protected $fillable = [
        'company_id', 'seller_user_id', 'sale_invoice_id',
        'base_amount', 'amount',
        'causation_basis', 'causation_date',
        'status', 'commission_settlement_id',
        'breakdown', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'causation_date' => 'datetime',
            'breakdown' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CommissionSettlement::class, 'commission_settlement_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeForSeller(Builder $q, int $sellerId): Builder
    {
        return $q->where('seller_user_id', $sellerId);
    }
}
