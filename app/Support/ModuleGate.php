<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Activación de módulos opcionales (restaurant, payroll, ecommerce,
 * etc.) por empresa. La empresa los lleva en companies.active_modules
 * (json array de keys). El superadmin los toggea desde el panel
 * SuperAdmin → Compañías.
 *
 * Resources / Pages que pertenecen a un módulo usan
 * ModuleGate::active('restaurant') en su canAccess.
 */
class ModuleGate
{
    /**
     * ¿La empresa activa tiene este módulo encendido?
     * Para superadmin o si no hay empresa activa: false (no aplica).
     */
    public static function active(string $module): bool
    {
        $current = app(CurrentCompany::class);
        if (! $current->isSet()) {
            // Para portal contador con empresa activa, currentCompany sí estará seteado.
            // Si no hay empresa activa (contador sin seleccionar, superadmin), no aplica.
            return false;
        }

        return $current->get()?->hasModule($module) ?? false;
    }
}
