<?php

namespace App\Models;

use App\Support\DianDvCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'document_type',
        'organization_type',
        'nit',
        'dv',
        'regime_type',
        'accounting_method',
        'inventory_method',
        'address',
        'city',
        'department',
        'country',
        'phone',
        'phone_country_code',
        'email',
        'website',
        'logo_path',
        'currency',
        'timezone',
        'active_modules',
        'settings',
        'active',
        'hidden_from_admin',
        'payroll_prefix',
        'payroll_next_consecutive',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'active_modules' => 'array',
            'settings' => 'array',
            'active' => 'boolean',
            'hidden_from_admin' => 'boolean',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    /**
     * Empresas que sí se listan en el superadmin.
     *
     * Ocultar no es desactivar: la empresa opera normal, sus usuarios entran
     * como siempre y su ficha sigue abriéndose por URL directa. Solo se cae de
     * los listados del panel de administración.
     */
    public function scopeVisibleInAdmin(Builder $query): Builder
    {
        return $query->where('hidden_from_admin', false);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function mainLocation(): ?Location
    {
        return $this->locations()->where('is_main', true)->first()
            ?? $this->locations()->orderBy('id')->first();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class);
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }

    public function commissionSettlements(): HasMany
    {
        return $this->hasMany(CommissionSettlement::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>=', now())
            ->latestOfMany('ends_at');
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    public function currentPlan(): ?Plan
    {
        return $this->activeSubscription?->plan;
    }

    public function hasFeature(string $key): bool
    {
        $plan = $this->currentPlan();

        return $plan ? $plan->hasFeature($key) : false;
    }

    public function planLimit(string $key): ?int
    {
        return $this->currentPlan()?->limit($key);
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->active_modules ?? [], true);
    }

    public function fullNit(): string
    {
        return DianDvCalculator::hasValue($this->dv)
            ? "{$this->nit}-{$this->dv}"
            : $this->nit;
    }
}
