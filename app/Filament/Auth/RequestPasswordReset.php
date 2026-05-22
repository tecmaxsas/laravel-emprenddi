<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Support\Enums\MaxWidth;

/**
 * Solicitud de recuperación de contraseña — compartida por los 3 portales.
 * Conserva la lógica de Filament y solo aplica el diseño moderno.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    protected static string $view = 'filament.auth.request-password-reset';

    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::FourExtraLarge;
    }
}
