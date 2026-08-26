<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

/**
 * Edición del perfil de usuario, compartida por los 3 paneles
 * (App, SuperAdmin y Contador) vía `->profile(EditProfile::class)`.
 *
 * Extiende la página base de Filament — el guardado, hashing de contraseña
 * y validación de email único los maneja la clase padre. Acá sólo se
 * agregan los campos extra del modelo User (last_name, marketing_opt_in)
 * y se cambian los labels a español de Colombia (tú).
 */
class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form->schema([
            $this->getNameFormComponent(),
            TextInput::make('last_name')
                ->label('Apellidos')
                ->maxLength(150),
            $this->getEmailFormComponent(),
            TextInput::make('username')
                ->label('Nombre de usuario')
                ->maxLength(60)
                ->unique('users', 'username', ignoreRecord: true)
                ->requiredWithout('email')
                ->rule('regex:/^[A-Za-z0-9._-]+$/')
                ->validationMessages([
                    'regex' => 'Solo letras, números, punto, guion y guion bajo — sin espacios ni arroba.',
                ])
                ->helperText('Puedes entrar con esto o con tu correo.'),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            Toggle::make('marketing_opt_in')
                ->label('Quiero recibir comunicaciones de Emprenddi')
                ->helperText('Avisos de nuevas funciones, tips y novedades. Puedes desactivarlo en cualquier momento.'),
        ]);
    }

    public function getTitle(): string
    {
        return 'Mi perfil';
    }

    public static function getNavigationLabel(): string
    {
        return 'Mi perfil';
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()->label('Nombre');
    }

    /**
     * El correo dejo de ser obligatorio: hay usuarios que solo tienen nombre
     * de usuario. Se exige tener al menos uno de los dos.
     */
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('Correo electrónico')
            ->required(false)
            ->requiredWithout('username')
            ->helperText('Sin correo no puedes recuperar tu contraseña por tu cuenta: tendría que restablecerla un administrador.');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Nueva contraseña')
            ->helperText('Déjalo en blanco si no quieres cambiarla.');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label('Confirmar nueva contraseña');
    }
}
