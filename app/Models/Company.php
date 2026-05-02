<?php

namespace App\Models;

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
        'trial_ends_at',
        'subscription_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'active_modules' => 'array',
            'settings' => 'array',
            'active' => 'boolean',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
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
        return $this->dv ? "{$this->nit}-{$this->dv}" : $this->nit;
    }
}
