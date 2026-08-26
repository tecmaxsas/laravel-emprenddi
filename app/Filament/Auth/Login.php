<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Support\Enums\MaxWidth;

/**
 * Página de login compartida por los 3 portales (app, super-admin,
 * contador). Conserva toda la lógica de autenticación de Filament y solo
 * reemplaza la vista por un diseño moderno de pantalla dividida.
 *
 * Acepta dos identificadores: correo o nombre de usuario. Algunos clientes
 * crean sus cajeros con nombres genéricos y sin correo real, así que el
 * campo decide contra qué columna autenticar según lo que se escriba.
 */
class Login extends BaseLogin
{
    protected static string $view = 'filament.auth.login';

    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::FourExtraLarge;
    }

    /**
     * El campo conserva el nombre 'email' a propósito: Filament reporta el
     * fallo de autenticación sobre 'data.email', así que renombrarlo dejaría
     * el mensaje de error sin dónde pintarse. Lo que cambia es la etiqueta y
     * que ya no se valida como correo.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Correo o nombre de usuario')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Si lo escrito parece un correo se autentica contra email; si no, contra
     * username. Nadie puede tener un nombre de usuario con forma de correo
     * —el formulario de usuarios lo impide—, así que no hay ambigüedad.
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $identificador = trim((string) ($data['email'] ?? ''));

        $campo = filter_var($identificador, FILTER_VALIDATE_EMAIL) !== false
            ? 'email'
            : 'username';

        return [
            $campo => $identificador,
            'password' => $data['password'],
        ];
    }
}
