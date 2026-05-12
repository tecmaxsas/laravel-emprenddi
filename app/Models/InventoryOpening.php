<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryOpening extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_POSTED => 'Contabilizado',
        self::STATUS_CANCELLED => 'Anulado',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'prefix',
        'number',
        'date',
        'counterpart_account_id',
        'status',
        'journal_entry_id',
        'created_by_user_id',
        'posted_by_user_id',
        'posted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
            'number' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterpart_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryOpeningLine::class)->orderBy('line_number');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isPosted(): bool { return $this->status === self::STATUS_POSTED; }
    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
}
