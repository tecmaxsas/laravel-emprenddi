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

            // 1. Singleton CurrentCompany seteado por middleware SetActiveCompany
            $companyId = app(CurrentCompany::class)->id();

            // 2. Fallback: company del usuario autenticado (cubre Livewire requests
            //    donde el middleware authMiddleware quizás no aplique).
            if (! $companyId) {
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
