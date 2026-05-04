<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use BelongsToCompany;

    public const TYPES = [
        'cash' => 'Efectivo',
        'debit_card' => 'Tarjeta débito',
        'credit_card' => 'Tarjeta de crédito',
        'bank_transfer' => 'Transferencia bancaria',
        'electronic' => 'PSE / Electrónico',
        'check' => 'Cheque',
        'credit_note' => 'Nota crédito',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'account_id',
        'requires_reference',
        'active',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
