<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Período de nómina — el lapso (mensual o quincenal) que se liquida.
 */
class PayrollPeriod extends Model
{
    use HasFactory, BelongsToCompany;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_LIQUIDATED = 'liquidated';
    public const STATUS_POSTED = 'posted';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_LIQUIDATED => 'Liquidada',
        self::STATUS_POSTED => 'Contabilizada',
    ];

    public const FREQUENCIES = [
        'mensual' => 'Mensual',
        'quincenal' => 'Quincenal',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'frequency',
        'start_date',
        'end_date',
        'payment_date',
        'status',
        'liquidated_at',
        'journal_entry_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'payment_date' => 'date',
            'liquidated_at' => 'datetime',
        ];
    }

    public function slips(): HasMany
    {
        return $this->hasMany(PayrollSlip::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isLiquidated(): bool
    {
        return $this->status === self::STATUS_LIQUIDATED;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }
}
