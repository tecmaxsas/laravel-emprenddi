<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'symbol',
        'decimals',
        'is_base',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_base' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)->orderByDesc('date');
    }

    public function latestRate(?string $date = null): ?ExchangeRate
    {
        $q = $this->exchangeRates();
        if ($date) {
            $q->whereDate('date', '<=', $date);
        }
        return $q->first();
    }

    protected static function booted(): void
    {
        // Solo puede haber una moneda base por empresa.
        static::saving(function (Currency $currency) {
            if ($currency->is_base) {
                static::query()
                    ->where('company_id', $currency->company_id)
                    ->where('id', '!=', $currency->id ?? 0)
                    ->update(['is_base' => false]);
            }
        });
    }
}
