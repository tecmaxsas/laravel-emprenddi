<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Empresa activa de la request. La setea el middleware (SetActiveCompany en
 * el panel App, SetAccountantActiveCompany en el de contador) y la consume
 * CompanyScope para filtrar TODOS los modelos multiempresa.
 *
 * OJO — por que existe scopeId() y no basta con id():
 * el middleware de empresa vive en el authMiddleware de cada panel, que NO
 * corre en las peticiones de Livewire (/livewire/update). Las tablas de
 * Filament cargan, buscan y paginan por ahi, asi que en esas requests el
 * singleton llegaba vacio y el scope no filtraba nada: se veian los datos de
 * todas las empresas. scopeId() resuelve un fallback desde el usuario
 * autenticado para que el filtro nunca dependa de si el middleware corrio.
 */
class CurrentCompany
{
    protected ?Company $company = null;

    /** Cache del fallback por request — evita tocar Auth en cada query. */
    protected ?int $fallbackId = null;

    protected bool $fallbackResolved = false;

    /**
     * Guard de reentrada. Resolver el fallback consulta el usuario, y esa
     * consulta puede volver a disparar el scope; sin esto, recursion.
     */
    protected bool $resolving = false;

    public function set(?Company $company): void
    {
        $this->company = $company;
        $this->forgetFallback();
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function isSet(): bool
    {
        return $this->company !== null;
    }

    public function clear(): void
    {
        $this->company = null;
        $this->forgetFallback();
    }

    /**
     * Id por el que CompanyScope debe filtrar.
     *
     *   int   filtrar por esa empresa
     *   0     usuario autenticado sin empresa resoluble — no debe ver nada
     *   null  sin filtro (consola, jobs, seeders, super admin)
     */
    public function scopeId(): ?int
    {
        if ($this->company !== null) {
            return $this->company->id;
        }

        if ($this->fallbackResolved) {
            return $this->fallbackId;
        }

        if ($this->resolving) {
            return null;
        }

        $this->resolving = true;
        try {
            $hasUser = Auth::hasUser() || Auth::check();
            $id = $hasUser ? $this->resolveFallbackId() : null;

            // Solo se cachea si habia usuario. Si una consulta ocurre antes de
            // que arranque la sesion, cachear ese null dejaria la request
            // entera sin filtro — justo el fallo que esto viene a cerrar.
            if ($hasUser) {
                $this->fallbackId = $id;
                $this->fallbackResolved = true;
            }
        } finally {
            $this->resolving = false;
        }

        return $id;
    }

    protected function resolveFallbackId(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        // El super admin opera sobre todas las empresas.
        if ($user->is_super_admin) {
            return null;
        }

        // Portal contador: la empresa activa vive en sesion, NO es la del
        // usuario. Si aun no eligio empresa, no debe ver datos de ninguna.
        if ($user->isAccountantPortal()) {
            $sessionCompanyId = (int) session('accountant_active_company_id', 0);

            return $sessionCompanyId > 0 ? $sessionCompanyId : 0;
        }

        return (int) ($user->company_id ?: 0);
    }

    protected function forgetFallback(): void
    {
        $this->fallbackId = null;
        $this->fallbackResolved = false;
    }
}
