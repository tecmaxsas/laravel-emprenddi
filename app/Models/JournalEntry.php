<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPES = [
        'manual' => 'Manual',
        'opening' => 'Apertura',
        'closing' => 'Cierre',
        'adjustment' => 'Ajuste',
        'sale' => 'Venta',
        'purchase' => 'Compra',
        'receipt' => 'Recibo de caja',
        'payment' => 'Comprobante de egreso',
        'payroll' => 'Nómina',
        'depreciation' => 'Depreciación',
        'reversal' => 'Reversión',
    ];

    public const STATUSES = [
        self::STATUS_DRAFT => 'Borrador',
        self::STATUS_POSTED => 'Contabilizado',
        self::STATUS_CANCELLED => 'Anulado',
    ];

    protected $fillable = [
        'company_id',
        'prefix',
        'number',
        'date',
        'type',
        'reference',
        'third_party_id',
        'description',
        'status',
        'total_debit',
        'total_credit',
        'posted_at',
        'posted_by_user_id',
        'created_by_user_id',
        'reversed_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'number' => 'integer',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    public function fullNumber(): string
    {
        return $this->prefix.'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isBalanced(): bool
    {
        return abs((float) $this->total_debit - (float) $this->total_credit) < 0.01;
    }

    public function recomputeTotals(): void
    {
        $this->total_debit = $this->lines->sum('debit');
        $this->total_credit = $this->lines->sum('credit');
    }

    protected static function booted(): void
    {
        // Bloquea creación/edición de asientos cuyo date cae en período
        // fiscal cerrado. Aplica también a updates (no se puede mover un
        // asiento a un mes cerrado).
        static::saving(function (JournalEntry $entry) {
            \App\Services\Accounting\FiscalPeriodGuard::ensureOpen(
                $entry->company_id,
                $entry->date,
            );
        });
    }
}
