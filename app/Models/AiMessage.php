<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mensaje de una conversación. Inmutable en la práctica: se escribe una vez.
 */
class AiMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public $timestamps = false;

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tools',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'created_at' => 'datetime',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function esDelUsuario(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /** Nombres legibles de las consultas que Claude hizo para responder. */
    public function consultasUsadas(): array
    {
        return collect($this->tools ?? [])->pluck('nombre')->filter()->unique()->values()->all();
    }
}
