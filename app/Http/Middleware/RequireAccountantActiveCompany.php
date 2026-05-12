<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si un contador no tiene empresa activa en sesión, lo redirige al
 * selector. Excluye explícitamente la ruta del selector para no causar
 * un loop, y la del cambio (que también va contra la página selectora).
 */
class RequireAccountantActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isAccountantPortal()) {
            return $next($request);
        }

        // Ya tiene empresa activa — sigue.
        if ($request->session()->has('accountant_active_company_id')) {
            return $next($request);
        }

        // Whitelist: el propio selector y livewire requests no deben redirigir.
        $path = $request->path();
        if (
            str_contains($path, 'contador/select-company') ||
            str_contains($path, 'contador/logout') ||
            str_contains($path, 'livewire/') ||
            $request->ajax()
        ) {
            return $next($request);
        }

        return redirect()->to('/contador/select-company');
    }
}
