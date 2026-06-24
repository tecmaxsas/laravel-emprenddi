<?php

namespace App\Models\Parking;

use App\Models\ThirdParty;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mensualidad (kind=individual) o convenio corporativo (kind=corporate).
 * Una placa esta cubierta si pertenece al array plates y la fecha esta
 * dentro de [start_date, end_date].
 */
class ParkingMembership extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const KIND_INDIVIDUAL = 'individual';
    public const KIND_CORPORATE = 'corporate';

    public const KINDS = [
        self::KIND_INDIVIDUAL => 'Mensualidad individual',
        self::KIND_CORPORATE => 'Convenio corporativo',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Activa',
        self::STATUS_EXPIRED => 'Vencida',
        self::STATUS_CANCELLED => 'Cancelada',
    ];

    protected $fillable = [
        'company_id',
        'parking_lot_id',
        'third_party_id',
        'vehicle_type_id',
        'kind',
        'plates',
        'name',
        'start_date',
        'end_date',
        'amount',
        'status',
        'auto_renew',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'plates' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'amount' => 'decimal:2',
            'auto_renew' => 'boolean',
        ];
    }

    public function parkingLot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ParkingSession::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        $today = now()->toDateString();
        $until = now()->addDays($days)->toDateString();
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $until);
    }

    public function isCurrentlyValid(?Carbon $at = null): bool
    {
        $at = $at ?: now();
        if ($this->status !== self::STATUS_ACTIVE) return false;
        if ($this->start_date && $at->lt($this->start_date)) return false;
        if ($this->end_date && $at->gt($this->end_date->endOfDay())) return false;
        return true;
    }

    public function coversPlate(string $plate): bool
    {
        $plate = strtoupper(preg_replace('/\s+/', '', trim($plate)));
        $plates = array_map(
            fn ($p) => strtoupper(preg_replace('/\s+/', '', trim((string) $p))),
            $this->plates ?? [],
        );
        return in_array($plate, $plates, true);
    }

    public function daysToExpiration(): ?int
    {
        if (! $this->end_date) return null;
        return (int) round(now()->startOfDay()->diffInDays($this->end_date->startOfDay(), false));
    }
}
