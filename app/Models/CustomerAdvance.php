<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plata que un cliente abono antes de que existiera la factura.
 *
 * Es un pasivo, no un menor valor de la cartera: hasta que se aplique a una
 * venta, la empresa le debe esa plata al cliente.
 */
class CustomerAdvance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'third_party_id',
        'date',
        'amount',
        'applied_amount',
        'payment_method',
        'account_id',
        'cash_register_session_id',
        'journal_entry_id',
        'reference',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'customer_advance_id');
    }

    /** Lo que queda por aplicar. Es el saldo a favor del cliente. */
    public function getAvailableAttribute(): float
    {
        return round((float) $this->amount - (float) $this->applied_amount, 2);
    }

    public function isExhausted(): bool
    {
        return $this->available <= 0.01;
    }
}
