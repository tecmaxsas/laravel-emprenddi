<?php

namespace App\Filament\Concerns;

/**
 * Verifica permisos Spatie en Filament Resources.
 * Cada resource implementa viewPermission() y opcionalmente managePermission()
 * (por default = view).
 *
 * El trait gobierna:
 *  - canAccess(): visibilidad del resource (menú + acceso a páginas)
 *  - canViewAny(): vista de la tabla
 *  - canCreate(), canEdit(), canDelete(): acciones de mutación
 */
trait ChecksPermission
{
    abstract protected static function viewPermission(): string;

    protected static function managePermission(): string
    {
        return static::viewPermission();
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can(static::managePermission());
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can(static::managePermission());
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can(static::managePermission());
    }
}
