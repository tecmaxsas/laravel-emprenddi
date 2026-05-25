<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/**
 * Gestion de usuarios de una empresa desde el SuperAdmin.
 *
 * Acciones criticas:
 *  - Reset password: util cuando el cliente perdio su clave o llama por
 *    soporte. Genera una nueva password aleatoria o usa una dictada.
 *  - Toggle activo: bloquea login sin borrar el historial.
 *
 * Crear usuario NO esta aqui — los usuarios se registran a si mismos
 * via /app/register o el admin de la empresa los crea desde Configuraciones.
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
            ->actions([
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
