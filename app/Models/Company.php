<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
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

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->active_modules ?? [], true);
    }

    public function fullNit(): string
    {
        return $this->dv ? "{$this->nit}-{$this->dv}" : $this->nit;
    }
}
