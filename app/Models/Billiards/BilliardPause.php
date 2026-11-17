<?php

namespace App\Models\Billiards;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pausa del reloj de la mesa, con su hora, su motivo y quien la hizo.
 *
 * Es la tabla que contesta el reclamo mas comun del salon: «yo pare la mesa
 * para ir a comer y me cobraron esa hora». Sin registro, esa discusion la
 * gana el que hable mas duro.
 */
class BilliardPause extends Model
{
    use BelongsToCompany;

    protected $table = 'billiard_session_pauses';

    protected $fillable = [
        'company_id',
        'session_id',
        'paused_at',
        'resumed_at',
        'reason',
        'paused_by_user_id',
        'resumed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BilliardSession::class, 'session_id');
    }

    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by_user_id');
    }

    public function resumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by_user_id');
    }

    public function segundos(): int
    {
        if (! $this->resumed_at) {
            return max(0, (int) $this->paused_at->diffInSeconds(now()));
        }

        return max(0, (int) $this->paused_at->diffInSeconds($this->resumed_at));
    }
}
