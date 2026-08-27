<?php

namespace App\Filament\Concerns;

use App\Support\AccountantContext;
use App\Support\ModuleGate;

/**
 * Verifica permisos Spatie en Filament Resources.
 * Cada resource implementa viewPermission() y opcionalmente managePermission()
 * (por default = view).
 *
 * El trait gobierna:
 *  - canAccess(): visibilidad del resource (menú + acceso a páginas)
 *  - canViewAny(): vista de la tabla
 *  - canCreate(), canEdit(), canDelete(): acciones de mutación
 *
 * Salvaguarda adicional para Portal Contador: si el usuario actual es
 * un contador externo (is_accountant_portal=true) sin empresa activa en
 * sesión, NINGÚN resource es accesible — el panel debe forzarlo al
 * selector de empresa primero. AccountantContext::ready() encapsula la
 * regla, también usada por páginas de reportes.
 */
trait ChecksPermission
{
    abstract protected static function viewPermission(): string;

    protected static function managePermission(): string
    {
        return static::viewPermission();
    }

    /**
     * Modulo que la empresa debe tener activo para ver este recurso.
     * Null = no depende de ningun modulo. El permiso decide si ESTE usuario
     * puede; el modulo decide si ESTA empresa lo tiene contratado.
     */
    protected static function requiredModule(): ?string
    {
        return null;
    }

    protected static function moduleAllows(): bool
    {
        $module = static::requiredModule();

        return $module === null || ModuleGate::active($module);
    }

    public static function canAccess(): bool
    {
        if (! AccountantContext::ready() || ! static::moduleAllows()) {
            return false;
        }

        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        if (! AccountantContext::ready() || ! static::moduleAllows()) {
            return false;
        }

        return (bool) auth()->user()?->can(static::managePermission());
    }

    public static function canEdit($record): bool
    {
        if (! AccountantContext::ready() || ! static::moduleAllows()) {
            return false;
        }

        return (bool) auth()->user()?->can(static::managePermission());
    }

    public static function canDelete($record): bool
    {
        if (! AccountantContext::ready() || ! static::moduleAllows()) {
            return false;
        }

        return (bool) auth()->user()?->can(static::managePermission());
    }
}
