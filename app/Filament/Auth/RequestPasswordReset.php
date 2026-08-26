<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
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

    /**
     * La recuperacion viaja por correo, asi que solo sirve a quien tenga uno.
     * Los usuarios creados con nombre de usuario y sin correo —cajeros, sobre
     * todo— dependen de que un administrador les restablezca la clave desde
     * Usuarios. Se dice aqui para que no se queden intentando.
     */
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->helperText('Si entras con nombre de usuario y no tienes correo registrado, pídele a un administrador de tu empresa que te restablezca la contraseña.');
    }
}
