<?php

namespace App\Traits;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if ($model->company_id) {
                return;
            }

            // Misma resolución que usa CompanyScope para leer, así lo que se
            // crea siempre cae en la empresa cuyos datos se están viendo.
            // Incluye el fallback al usuario autenticado, necesario en las
            // requests de Livewire (el middleware de empresa no corre ahí).
            $companyId = app(CurrentCompany::class)->scopeId() ?: null;

            // En el portal contador la empresa activa vive en sesión: si no
            // se pudo resolver, NO se cae a la empresa propia del contador.
            if (! $companyId && ! Auth::user()?->isAccountantPortal()) {
                $companyId = Auth::user()?->company_id;
            }

            $model->company_id = $companyId;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
