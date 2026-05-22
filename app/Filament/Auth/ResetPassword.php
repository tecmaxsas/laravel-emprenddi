<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Support\Enums\MaxWidth;

/**
 * Restablecer la contraseña con el token enviado por correo — compartida
 * por los 3 portales. Conserva la lógica de Filament.
 */
class ResetPassword extends BaseResetPassword
{
    protected static string $view = 'filament.auth.reset-password';

    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::FourExtraLarge;
    }
}
