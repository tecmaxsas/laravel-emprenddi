<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una conversación con Claude sobre los datos del negocio.
 */
class AiConversation extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'user_id',
        'title',
        'model',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lo que costó la conversación entera, en dólares. */
    public function costoTotal(): float
    {
        return (float) $this->messages()->sum('cost_usd');
    }

    /**
     * Un título a partir de la primera pregunta.
     *
     * Nadie titula sus conversaciones; si el sistema no lo hace, la lista queda
     * llena de «Conversación 1, 2, 3» y no se puede retomar ninguna.
     */
    public static function tituloDesde(string $pregunta): string
    {
        $limpio = trim(preg_replace('/\s+/', ' ', $pregunta));

        return mb_substr($limpio, 0, 80) ?: 'Nueva conversación';
    }
}
