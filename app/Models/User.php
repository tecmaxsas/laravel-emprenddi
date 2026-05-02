<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'company_id',
        'name',
        'last_name',
        'email',
        'password',
        'is_super_admin',
        'active',
        'accepted_terms_at',
        'marketing_opt_in',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'accepted_terms_at' => 'datetime',
            'marketing_opt_in' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->active) {
            return false;
        }

        return match ($panel->getId()) {
            'super-admin' => $this->is_super_admin,
            'app' => ! $this->is_super_admin && $this->company_id !== null && (bool) $this->company?->active,
            default => false,
        };
    }
}
