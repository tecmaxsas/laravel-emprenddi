<?php

namespace App\Models\Billiards;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una mesa del salon. Es el tile del mapa del terminal.
 *
 * El estado `occupied` cubre tambien la mesa en pausa: el reloj esta
 * detenido pero la mesa sigue tomada, nadie mas se puede sentar ahi.
 */
class BilliardTable extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'billiard_tables';

    public const STATUS_FREE = 'free';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [
        self::STATUS_FREE => 'Libre',
        self::STATUS_OCCUPIED => 'Ocupada',
        self::STATUS_RESERVED => 'Reservada',
        self::STATUS_MAINTENANCE => 'Mantenimiento',
    ];

    public const STATUS_COLORS = [
        self::STATUS_FREE => '#16a34a',
        self::STATUS_OCCUPIED => '#dc2626',
        self::STATUS_RESERVED => '#f59e0b',
        self::STATUS_MAINTENANCE => '#6b7280',
    ];

    protected $fillable = [
        'company_id',
        'hall_id',
        'table_type_id',
        'code',
        'zone',
        'status',
        'sort_order',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(BilliardHall::class, 'hall_id');
    }

    public function tableType(): BelongsTo
    {
        return $this->belongsTo(BilliardTableType::class, 'table_type_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BilliardSession::class, 'table_id');
    }

    public function openSessions(): HasMany
    {
        return $this->hasMany(BilliardSession::class, 'table_id')
            ->where('status', BilliardSession::STATUS_OPEN);
    }

    public function scopeFree(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FREE);
    }

    public function isFree(): bool
    {
        return $this->status === self::STATUS_FREE;
    }

    /** Como se nombra en voz alta: «Mesa 4». */
    public function etiqueta(): string
    {
        return 'Mesa '.$this->code;
    }
}
