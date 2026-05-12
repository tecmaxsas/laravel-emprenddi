<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Helper para el Portal Contador. ready() responde "este usuario puede
 * ver/usar contenido scoped a una empresa". Para usuarios normales del
 * panel app o super-admin siempre devuelve true; para contadores
 * externos exige tener una empresa activa en sesión.
 *
 * Usado tanto por ChecksPermission (Resources) como por las páginas de
 * reportes (que tienen canAccess propio).
 */
class AccountantContext
{
    public static function ready(): bool
    {
        $user = Auth::user();
        if (! $user) return false;
        if (! ($user->is_accountant_portal ?? false)) return true;
        return session()->has('accountant_active_company_id');
    }
}
