<?php

namespace App\Support;

use App\Models\Company;
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
     * Contabilidad. A diferencia de los demas modulos, este solo OCULTA:
     * el motor contable sigue generando asientos por debajo aunque este
     * apagado, para que al activarlo se vean los libros completos desde el
     * primer dia en vez de arrancar vacios a mitad de vida.
     */
    public const ACCOUNTING = 'accounting';

    /**
     * ¿La empresa activa tiene este módulo encendido?
     *
     * Resuelve igual que CompanyScope y BelongsToCompany, con el fallback al
     * usuario autenticado: el middleware de empresa NO corre en las requests
     * de Livewire (/livewire/update), asi que ahi CurrentCompany viene vacia.
     * Sin el fallback, cualquier ModuleGate::active() dentro de una accion de
     * Livewire devolvia false y el sistema se comportaba como si la empresa no
     * tuviera ningun modulo — por eso la apertura de caja de un parqueadero
     * terminaba mandando al POS retail.
     *
     * En el portal contador la empresa activa vive en sesion: si no se pudo
     * resolver, NO se cae a la empresa propia del contador.
     */
    public static function active(string $module): bool
    {
        $companyId = app(CurrentCompany::class)->scopeId() ?: null;

        if (! $companyId && ! Auth::user()?->isAccountantPortal()) {
            $companyId = Auth::user()?->company_id;
        }

        if (! $companyId) {
            return false;
        }

        return Company::find($companyId)?->hasModule($module) ?? false;
    }
}
