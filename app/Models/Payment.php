<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const PAYMENT_METHODS = [
        'cash' => 'Efectivo',
        'bank_transfer' => 'Transferencia bancaria',
        'check' => 'Cheque',
        'credit_card' => 'Tarjeta de crédito',
        'debit_card' => 'Tarjeta débito',
        'electronic' => 'PSE / Pago electrónico',
        'credit_note' => 'Nota crédito aplicada',
        'gift_card' => 'Tarjeta regalo',
        'advance' => 'Anticipo del cliente',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id',
        'paymentable_type',
        'paymentable_id',
        'third_party_id',
        'customer_advance_id',
        'date',
        'amount',
        'payment_method',
        'account_id',
        'cash_register_session_id',
        'reference',
        'description',
        'journal_entry_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function paymentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Payment $payment) {
            \App\Services\Accounting\FiscalPeriodGuard::ensureOpen(
                $payment->company_id,
                $payment->date,
            );
        });
    }
}
