<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISPOSED = 'disposed';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Activo',
        self::STATUS_DISPOSED => 'Dado de baja',
    ];

    public const CATEGORIES = [
        'land' => 'Terreno',
        'building' => 'Edificación',
        'vehicle' => 'Vehículo',
        'machinery' => 'Maquinaria',
        'equipment' => 'Equipo',
        'computer' => 'Computador',
        'furniture' => 'Mueble / Enseres',
        'tool' => 'Herramienta',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'code',
        'name',
        'description',
        'category',
        'purchased_at',
        'activated_at',
        'disposed_at',
        'cost',
        'residual_value',
        'useful_life_months',
        'depreciation_method',
        'accumulated_depreciation',
        'asset_account_id',
        'depreciation_account_id',
        'depreciation_expense_account_id',
        'purchase_invoice_id',
        'status',
        'disposal_sale_price',
        'disposal_journal_entry_id',
        'disposal_notes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'activated_at' => 'date',
            'disposed_at' => 'date',
            'cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'useful_life_months' => 'integer',
            'accumulated_depreciation' => 'decimal:2',
            'disposal_sale_price' => 'decimal:2',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('year')->orderBy('month');
    }

    public function disposalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposal_journal_entry_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDisposed(): bool
    {
        return $this->status === self::STATUS_DISPOSED;
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    /**
     * Depreciación mensual lineal = (cost - residual) / vida útil.
     * No tiene en cuenta meses parciales — el contador puede decidir
     * arrancar el depreciation desde activated_at.
     */
    public function monthlyDepreciation(): float
    {
        $base = max((float) $this->cost - (float) $this->residual_value, 0);
        $months = max((int) $this->useful_life_months, 1);
        return round($base / $months, 2);
    }

    public function fullyDepreciated(): bool
    {
        return (float) $this->accumulated_depreciation
             + 0.01 >= (float) $this->cost - (float) $this->residual_value;
    }
}
