<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    public const TYPES = [
        'vat' => 'IVA',
        'consumption_tax' => 'Impuesto al Consumo (INC)',
        'income_withholding' => 'Retención en la fuente (Renta)',
        'vat_withholding' => 'Retención de IVA (ReteIVA)',
        'ica_withholding' => 'Retención de ICA (ReteICA)',
        'other' => 'Otro',
    ];

    public const APPLIES_TO = [
        'sale' => 'Solo Ventas',
        'purchase' => 'Solo Compras',
        'both' => 'Ventas y Compras',
    ];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'rate',
        'takeaway_tax_id',
        'sale_account_id',
        'purchase_account_id',
        'minimum_base',
        'applies_to',
        'is_default_for_sale',
        'is_default_for_purchase',
        'is_active',
        'is_system',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'minimum_base' => 'decimal:2',
            'is_default_for_sale' => 'boolean',
            'is_default_for_purchase' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function saleAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sale_account_id');
    }

    /**
     * Impuesto a usar cuando la orden es "para llevar" / delivery.
     */
    public function takeawayTax(): BelongsTo
    {
        return $this->belongsTo(self::class, 'takeaway_tax_id');
    }

    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'purchase_account_id');
    }

    public function isWithholding(): bool
    {
        return in_array($this->type, [
            'income_withholding',
            'vat_withholding',
            'ica_withholding',
        ], true);
    }

    /**
     * Calcula el valor del impuesto sobre una base, respetando minimum_base.
     */
    public function calculate(float $base): float
    {
        if ($base < (float) $this->minimum_base) {
            return 0.0;
        }

        return round($base * ((float) $this->rate / 100), 2);
    }
}
