<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Support\Enums\MaxWidth;

/**
 * Página de login compartida por los 3 portales (app, super-admin,
 * contador). Conserva toda la lógica de autenticación de Filament y solo
 * reemplaza la vista por un diseño moderno de pantalla dividida.
 */
class Login extends BaseLogin
{
    protected static string $view = 'filament.auth.login';

    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::FourExtraLarge;
    }
}
