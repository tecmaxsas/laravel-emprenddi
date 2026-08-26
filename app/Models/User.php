<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'last_name',
        'email',
        'username',
        'password',
        'is_super_admin',
        'is_accountant_portal',
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
            'is_accountant_portal' => 'boolean',
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

    /**
     * Empresas que este contador (portal externo) tiene autorización
     * para llevar. Many-to-many vía accountant_companies.
     */
    public function managedCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'accountant_companies')
            ->using(AccountantCompany::class)  // pivot con casts (granted_at => datetime)
            ->withPivot(['active', 'granted_at', 'granted_by_user_id', 'notes'])
            ->withTimestamps();
    }

    public function activeManagedCompanies(): BelongsToMany
    {
        return $this->managedCompanies()->wherePivot('active', true);
    }

    public function isAccountantPortal(): bool
    {
        return (bool) $this->is_accountant_portal;
    }

    public function canManageCompany(int $companyId): bool
    {
        if (! $this->isAccountantPortal()) {
            return false;
        }
        return $this->activeManagedCompanies()->where('companies.id', $companyId)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->active) {
            return false;
        }

        return match ($panel->getId()) {
            'super-admin' => $this->is_super_admin,
            'app' => ! $this->is_super_admin
                && ! $this->is_accountant_portal
                && $this->company_id !== null
                && (bool) $this->company?->active
                && $this->company->hasActiveSubscription(),
            'contador' => (bool) $this->is_accountant_portal,
            default => false,
        };
    }

    /**
     * El username se guarda siempre en mayusculas.
     *
     * Postgres compara cadenas distinguiendo mayusculas, asi que sin
     * normalizar "Juan" y "juan" serian dos usuarios distintos y quien se
     * registro con uno no podria entrar escribiendo el otro. El login
     * normaliza igual antes de autenticar.
     */
    protected function username(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            set: fn (?string $value) => filled($value) ? strtoupper(trim($value)) : null,
        );
    }

    /**
     * Sufijo de empresa que se le pega al nombre de usuario.
     *
     * El indice de username es unico GLOBAL —el login no sabe de que empresa
     * viene quien entra—, asi que sin el sufijo el "CAJERO" de un cliente
     * chocaria con el de otro. Pegandolo, cada empresa puede usar los nombres
     * que quiera sin pisarse con nadie.
     */
    public static function companySuffix(?int $companyId): string
    {
        return $companyId ? '-'.$companyId : '';
    }

    /**
     * Compone el username final a partir de lo que escribio el usuario.
     * Normaliza a mayusculas y descarta lo que no sea alfanumerico, punto o
     * guion bajo — el guion queda reservado como separador del sufijo.
     */
    public static function withCompanySuffix(?string $base, ?int $companyId): ?string
    {
        $base = strtoupper(trim((string) $base));
        $base = preg_replace('/[^A-Z0-9._]+/', '', $base);

        if ($base === '') {
            return null;
        }

        return $base.self::companySuffix($companyId);
    }

    /**
     * Quita el sufijo para mostrar en el formulario solo la parte editable.
     */
    public static function stripCompanySuffix(?string $username, ?int $companyId): ?string
    {
        if (blank($username)) {
            return null;
        }

        $suffix = self::companySuffix($companyId);

        return ($suffix !== '' && str_ends_with($username, $suffix))
            ? substr($username, 0, -strlen($suffix))
            : $username;
    }
}
