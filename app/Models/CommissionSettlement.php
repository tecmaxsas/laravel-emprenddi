<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Liquidacion de comisiones de un vendedor en un periodo. Agrupa las
 * CommissionEntry 'pending' del periodo, las marca 'settled' y genera
 * el asiento contable (DR Gasto comisiones / CR Comisiones por pagar).
 */
class CommissionSettlement extends Model
{
    use HasFactory, BelongsToCompany;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'company_id', 'seller_user_id',
        'period_start', 'period_end',
        'total_amount', 'entries_count',
        'status', 'journal_entry_id',
        'settled_at', 'settled_by_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_amount' => 'decimal:2',
            'entries_count' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }

    public function scopeDraft(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DRAFT);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
