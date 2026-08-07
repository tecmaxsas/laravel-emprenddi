<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Gestion de usuarios de una empresa desde el SuperAdmin.
 *
 * Acciones criticas:
 *  - Crear usuario admin: bootstrap del primer usuario de la empresa
 *    (chicken-and-egg — sin admin nadie puede crear usuarios desde el
 *    panel de la propia empresa).
 *  - Editar datos basicos (nombre, email, roles, activo).
 *  - Reset password: util cuando el cliente perdio su clave o llama por
 *    soporte. Genera una nueva password aleatoria o usa una dictada.
 *  - Toggle activo: bloquea login sin borrar el historial.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Usuarios';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(['name', 'last_name'])
                    ->formatStateUsing(fn ($record) => trim($record->name.' '.$record->last_name)),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Último login')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Nunca'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Estado'),
            ])
            ->headerActions([
                // Bootstrap del primer usuario admin. Sin esta accion, una
                // empresa recien creada quedaba sin nadie que pueda entrar.
                Tables\Actions\Action::make('createUser')
                    ->label('Crear usuario')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->modalHeading('Crear usuario para esta empresa')
                    ->modalDescription('Se creará con la contraseña que definas. Marca "admin" para dar acceso completo a las configuraciones de la empresa.')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombres')->required()->maxLength(150),
                        Forms\Components\TextInput::make('last_name')
                            ->label('Apellidos')->maxLength(150),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')->required()->email()->maxLength(150)
                            ->rules(['email'])
                            ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('email', strtolower(trim((string) $state)))),
                        Forms\Components\Toggle::make('generate_random')
                            ->label('Generar contraseña aleatoria')->default(true)->live(),
                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña')->password()->revealable()->minLength(8)
                            ->required(fn (Forms\Get $get) => ! $get('generate_random'))
                            ->visible(fn (Forms\Get $get) => ! $get('generate_random'))
                            ->helperText('Mínimo 8 caracteres.'),
                        Forms\Components\CheckboxList::make('roles')
                            ->label('Roles')
                            ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->default(['admin'])
                            ->columns(3)
                            ->required(),
                        Forms\Components\Toggle::make('active')->label('Activo')->default(true),
                    ])
                    ->action(function (array $data) {
                        $companyId = $this->getOwnerRecord()->id;

                        // Validar email unico global (Users no scope por empresa aqui;
                        // el email es unico en users.email)
                        $emailExists = User::query()->where('email', $data['email'])->exists();
                        if ($emailExists) {
                            Notification::make()->danger()
                                ->title('Email ya registrado')
                                ->body('Ya existe un usuario con ese email en el sistema.')
                                ->send();
                            return;
                        }

                        $password = $data['generate_random']
                            ? self::generateReadablePassword()
                            : $data['password'];

                        $user = User::create([
                            'company_id' => $companyId,
                            'name' => trim($data['name']),
                            'last_name' => trim($data['last_name'] ?? ''),
                            'email' => strtolower(trim($data['email'])),
                            'password' => Hash::make($password),
                            'active' => (bool) ($data['active'] ?? true),
                        ]);
                        $user->syncRoles($data['roles'] ?? []);

                        Notification::make()->success()
                            ->title('Usuario creado')
                            ->body("Email: {$user->email}\nContraseña: {$password}\n\nComparte por canal seguro — es la única vez que se muestra.")
                            ->persistent()->send();
                    }),
            ])
            ->actions([
                // Editar datos basicos: nombre, email, roles, activo (sin password).
                Tables\Actions\Action::make('editUser')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Editar usuario {$record->email}")
                    ->fillForm(fn ($record) => [
                        'name' => $record->name,
                        'last_name' => $record->last_name,
                        'email' => $record->email,
                        'roles' => $record->roles->pluck('name')->all(),
                        'active' => $record->active,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('name')->label('Nombres')->required()->maxLength(150),
                        Forms\Components\TextInput::make('last_name')->label('Apellidos')->maxLength(150),
                        Forms\Components\TextInput::make('email')->label('Email')->required()->email()->maxLength(150),
                        Forms\Components\CheckboxList::make('roles')
                            ->label('Roles')
                            ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                            ->columns(3),
                        Forms\Components\Toggle::make('active')->label('Activo'),
                    ])
                    ->action(function (array $data, $record) {
                        $newEmail = strtolower(trim($data['email']));
                        // Validar email unico si cambio
                        if ($newEmail !== $record->email) {
                            $exists = User::query()->where('email', $newEmail)->where('id', '!=', $record->id)->exists();
                            if ($exists) {
                                Notification::make()->danger()->title('Email ya registrado por otro usuario')->send();
                                return;
                            }
                        }

                        $record->update([
                            'name' => trim($data['name']),
                            'last_name' => trim($data['last_name'] ?? ''),
                            'email' => $newEmail,
                            'active' => (bool) ($data['active'] ?? true),
                        ]);
                        $record->syncRoles($data['roles'] ?? []);

                        Notification::make()->success()->title('Usuario actualizado')->send();
                    }),

                // Reset / cambio de password — accion principal del SuperAdmin
                // para soporte. Permite generar password aleatoria o dictarla.
                Tables\Actions\Action::make('resetPassword')
                    ->label('Cambiar contraseña')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->modalHeading(fn ($record) => "Cambiar contraseña de {$record->email}")
                    ->modalDescription('La nueva contraseña reemplaza la actual de inmediato. Comparte la clave por un canal seguro.')
                    ->form([
                        Forms\Components\Toggle::make('generate_random')
                            ->label('Generar contraseña aleatoria')
                            ->default(true)
                            ->live(),
                        Forms\Components\TextInput::make('password')
                            ->label('Nueva contraseña')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (Forms\Get $get) => ! $get('generate_random'))
                            ->visible(fn (Forms\Get $get) => ! $get('generate_random'))
                            ->helperText('Mínimo 8 caracteres.'),
                    ])
                    ->action(function (array $data, $record) {
                        $newPassword = $data['generate_random']
                            ? self::generateReadablePassword()
                            : $data['password'];

                        $record->update(['password' => Hash::make($newPassword)]);

                        Notification::make()
                            ->title('Contraseña actualizada')
                            ->body("Nueva contraseña para {$record->email}:\n\n{$newPassword}\n\nComparte por canal seguro. Esta es la única vez que se muestra.")
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                // Toggle rapido activo/inactivo sin entrar a editar
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn ($record) => $record->active ? 'Desactivar' : 'Activar')
                    ->icon(fn ($record) => $record->active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn ($record) => $record->active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => $record->active
                        ? "Desactivar usuario {$record->email}"
                        : "Activar usuario {$record->email}")
                    ->modalDescription(fn ($record) => $record->active
                        ? 'El usuario no podrá iniciar sesión. Su histórico se mantiene intacto.'
                        : 'El usuario podrá volver a iniciar sesión.')
                    ->action(function ($record) {
                        $record->update(['active' => ! $record->active]);
                        Notification::make()
                            ->title($record->active ? 'Usuario activado' : 'Usuario desactivado')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Genera password legible para humanos: 4 grupos consonante+vocal +
     * 2 digitos. Ej: 'kuremasi42'. Mejor que random ilegible cuando hay
     * que dictarla por telefono.
     */
    private static function generateReadablePassword(): string
    {
        $consonants = 'bcdfghkmnprstvwxz';
        $vowels = 'aeiou';
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= $consonants[random_int(0, strlen($consonants) - 1)];
            $out .= $vowels[random_int(0, strlen($vowels) - 1)];
        }
        $out .= random_int(10, 99);
        return $out;
    }
}
