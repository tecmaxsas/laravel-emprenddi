<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento del saldo de Claude. Ver la migración para el porqué del
 * `balance_after`.
 */
class AiCreditMovement extends Model
{
    use BelongsToCompany;

    public const TYPE_RECARGA = 'recarga';

    public const TYPE_CONSUMO = 'consumo';

    public const TYPE_AJUSTE = 'ajuste';

    public const TYPES = [
        self::TYPE_RECARGA => 'Recarga',
        self::TYPE_CONSUMO => 'Consumo',
        self::TYPE_AJUSTE => 'Ajuste',
    ];

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'type',
        'amount_cop',
        'balance_after',
        'description',
        'ai_conversation_id',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cop' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
