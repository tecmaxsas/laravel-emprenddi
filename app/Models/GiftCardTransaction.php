<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de saldo en una gift card. Inmutable: nunca se edita,
 * solo se inserta. Cualquier mutacion de saldo de la GiftCard pasa por
 * los metodos charge() / refund() / cancel() que insertan un registro
 * aqui automaticamente.
 */
class GiftCardTransaction extends Model
{
    use HasFactory, BelongsToCompany;

    public const TYPE_ISSUE = 'issue';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_REFUND = 'refund';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_CANCEL = 'cancel';

    protected $fillable = [
        'company_id', 'gift_card_id', 'type',
        'amount', 'balance_after',
        'sale_invoice_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function saleInvoice(): BelongsTo
    {
        return $this->belongsTo(SaleInvoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
