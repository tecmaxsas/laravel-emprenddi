<?php

namespace App\Models\OrderTaking;

use App\Models\Account;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToCompany;

    protected $table = 'order_taking_payments';

    public const METHODS = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        'check' => 'Cheque',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id', 'order_id',
        'payment_date', 'amount', 'payment_method', 'account_id',
        'reference', 'notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
