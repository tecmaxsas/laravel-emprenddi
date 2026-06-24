<?php

namespace App\Models\Parking;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParkingIncident extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const KIND_DAMAGE = 'damage';
    public const KIND_THEFT = 'theft';
    public const KIND_ACCIDENT = 'accident';
    public const KIND_IRREGULAR = 'irregular';
    public const KIND_MAINTENANCE = 'maintenance';
    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_DAMAGE => 'Daño',
        self::KIND_THEFT => 'Robo / Hurto',
        self::KIND_ACCIDENT => 'Accidente',
        self::KIND_IRREGULAR => 'Conducta irregular',
        self::KIND_MAINTENANCE => 'Mantenimiento',
        self::KIND_OTHER => 'Otro',
    ];

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_LOW => 'Bajo',
        self::SEVERITY_MEDIUM => 'Medio',
        self::SEVERITY_HIGH => 'Alto',
        self::SEVERITY_CRITICAL => 'Crítico',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_OPEN => 'Abierto',
        self::STATUS_INVESTIGATING => 'En revisión',
        self::STATUS_RESOLVED => 'Resuelto',
        self::STATUS_DISMISSED => 'Descartado',
    ];

    protected $fillable = [
        'company_id',
        'parking_lot_id',
        'parking_session_id',
        'kind',
        'severity',
        'status',
        'plate',
        'description',
        'photo_paths',
        'reported_by_user_id',
        'resolved_by_user_id',
        'resolved_at',
        'resolved_notes',
    ];

    protected function casts(): array
    {
        return [
            'photo_paths' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function parkingLot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ParkingSession::class, 'parking_session_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_INVESTIGATING]);
    }
}
