<?php

namespace App\Models\Hotel;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Una temporada: un rango de fechas con su propio precio por noche.
 *
 * Las dos fechas son INCLUSIVAS, al revés que el rango de una estadía. Una
 * temporada no son noches, son días del calendario: «del 15 de diciembre al
 * 15 de enero» incluye los dos, que es como lo dice cualquiera.
 */
class RateSeason extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'hotel_rate_seasons';

    protected $fillable = [
        'company_id',
        'hotel_id',
        'room_type_id',
        'name',
        'start_date',
        'end_date',
        'rate',
        'extra_person_rate',
        'priority',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'rate' => 'decimal:2',
            'extra_person_rate' => 'decimal:2',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    /**
     * Las temporadas que cubren esa noche, de la que manda a la que menos.
     *
     * El desempate importa y es este, en orden:
     *
     *   1. La de mayor prioridad. Es lo que deja meter un puente de tres días
     *      dentro de una temporada alta de un mes.
     *   2. A igual prioridad, la que apunta a un tipo concreto le gana a la
     *      que aplica a todos: lo específico manda sobre lo general.
     *   3. Y si aún empatan, la más nueva, que es la última decisión que
     *      alguien tomó.
     */
    public function scopeQueCubren(Builder $query, Carbon|string $noche, ?int $roomTypeId = null): Builder
    {
        $fecha = ($noche instanceof Carbon ? $noche->copy() : Carbon::parse($noche))->toDateString();

        return $query
            ->where('active', true)
            ->whereDate('start_date', '<=', $fecha)
            ->whereDate('end_date', '>=', $fecha)
            ->where(fn ($q) => $q
                ->whereNull('room_type_id')
                ->when($roomTypeId, fn ($sub) => $sub->orWhere('room_type_id', $roomTypeId)))
            ->orderByDesc('priority')
            ->orderByRaw('CASE WHEN room_type_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('id');
    }

    public function cubre(Carbon|string $noche): bool
    {
        $fecha = ($noche instanceof Carbon ? $noche->copy() : Carbon::parse($noche))->startOfDay();

        return $fecha->gte($this->start_date->copy()->startOfDay())
            && $fecha->lte($this->end_date->copy()->startOfDay());
    }

    public function dias(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function estaVigente(): bool
    {
        return $this->active && $this->end_date->copy()->endOfDay()->gte(now());
    }
}
