<?php

namespace App\Filament\Contador\Pages\Auth;

use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\PermissionRegistrar;

class AccountantRegister extends BaseRegister
{
    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(120),

            TextInput::make('last_name')
                ->label('Apellido')
                ->required()
                ->maxLength(120),

            TextInput::make('email')
                ->label('Correo electrónico')
                ->email()
                ->required()
                ->maxLength(120)
                ->unique(User::class),

            TextInput::make('password')
                ->label('Contraseña')
                ->password()
                ->required()
                ->rule(Password::default())
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->same('password_confirmation')
                ->validationAttribute('contraseña'),

            TextInput::make('password_confirmation')
                ->label('Confirma la contraseña')
                ->password()
                ->required()
                ->dehydrated(false),
        ])->statePath('data');
    }

    protected function handleRegistration(array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],  // ya viene hasheada por dehydrateStateUsing
            'is_accountant_portal' => true,
            'is_super_admin' => false,
            'company_id' => null,
            'active' => true,
            'accepted_terms_at' => now(),
        ]);

        // Rol con permisos contables limitados
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole('accountant_external');

        return $user;
    }

    public function getHeading(): string
    {
        return 'Registro de contador';
    }

    public function getSubheading(): ?string
    {
        return 'Crea tu cuenta. Una vez registrado, el administrador del cliente debe vincularte sus empresas para que puedas trabajar.';
    }
}
