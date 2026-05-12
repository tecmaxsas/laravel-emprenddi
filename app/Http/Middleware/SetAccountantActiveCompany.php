<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Para usuarios del portal contador: lee la empresa activa de la sesión
 * (clave 'accountant_active_company_id'), valida que el contador tenga
 * acceso vigente a ella, y la setea en el singleton CurrentCompany.
 *
 * Si no hay empresa activa en sesión, deja CurrentCompany vacío y el
 * panel mostrará el selector de empresa.
 */
class SetAccountantActiveCompany
{
    public function __construct(protected CurrentCompany $currentCompany)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAccountantPortal()) {
            return $next($request);
        }

        $companyId = $request->session()->get('accountant_active_company_id');
        if (! $companyId) {
            return $next($request);
        }

        // Validar que el contador todavía tiene acceso a esa empresa
        if (! $user->canManageCompany((int) $companyId)) {
            $request->session()->forget('accountant_active_company_id');
            return $next($request);
        }

        $company = Company::find($companyId);
        if ($company) {
            $this->currentCompany->set($company);
        }

        return $next($request);
    }
}
