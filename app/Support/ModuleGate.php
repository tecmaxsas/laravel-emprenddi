<?php

namespace App\Support;

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
     * Contabilidad. A diferencia de los demas modulos, este solo OCULTA:
     * el motor contable sigue generando asientos por debajo aunque este
     * apagado, para que al activarlo se vean los libros completos desde el
     * primer dia en vez de arrancar vacios a mitad de vida.
     */
    public const ACCOUNTING = 'accounting';

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
