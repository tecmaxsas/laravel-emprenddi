<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\UserResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'users.view'; }
    protected static function managePermission(): string { return 'users.manage'; }

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?string $navigationGroup = 'Gestión de usuarios';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        // Solo usuarios de la empresa actual; excluye superadmins de otras empresas
        return parent::getEloquentQuery()
            ->where('company_id', auth()->user()?->company_id)
            ->where('is_super_admin', false);
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Section::make('Datos personales')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('last_name')
                        ->label('Apellido')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Opcional. Sin correo, este usuario entra con su nombre de usuario y solo un administrador puede restablecerle la contraseña.')
                        ->requiredWithout('username')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('username')
                        ->label('Nombre de usuario')
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->requiredWithout('email')
                        // El sufijo con el id de la empresa mantiene los
                        // nombres legibles y evita chocar con el "CAJERO" de
                        // otro cliente: el indice es unico global porque el
                        // login no sabe de que empresa viene quien entra.
                        ->default(fn () => \App\Models\User::suggestUsername('CAJERO', auth()->user()?->company_id))
                        ->rule('regex:/^[A-Za-z0-9._-]+$/')
                        ->validationMessages([
                            'regex' => 'Solo letras, números, punto, guion y guion bajo — sin espacios ni arroba.',
                        ])
                        ->helperText('Con esto entra al sistema. Se sugiere el sufijo de la empresa para que no choque con otros clientes.')
                        ->columnSpan(2),
                ])->columnSpanFull(),

            Forms\Components\Section::make('Acceso')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Contraseña')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->minLength(8)
                        ->rule(Password::default())
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText(fn (string $context) => $context === 'edit'
                            ? 'Déjalo vacío para no cambiar la contraseña actual.'
                            : null),

                    Forms\Components\Toggle::make('active')
                        ->label('Usuario activo')
                        ->default(true)
                        ->helperText('Si lo apagas, el usuario no podrá iniciar sesión.'),
                ])->columnSpanFull(),

            Forms\Components\Section::make('Roles y permisos')
                ->description('Los permisos se otorgan por los roles asignados. Para editar qué permisos tiene cada rol, ve a Roles.')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('Roles asignados')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->options(fn () => Role::query()
                            ->orderBy('name')
                            ->pluck('name', 'name')
                            ->all())
                        ->saveRelationshipsUsing(function ($component, $state, $record) {
                            $record->syncRoles($state ?? []);
                        }),
                ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->state(fn (User $record) => trim($record->name.' '.$record->last_name))
                    ->searchable(['name', 'last_name'])
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->placeholder('—')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('username')
                    ->label('Usuario')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(','),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Último login')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('Nunca')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->date('Y-m-d')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rol')
                    ->relationship('roles', 'name')
                    ->multiple(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reset_password')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->modalHeading('Resetear contraseña')
                    ->modalDescription(fn (User $record) => "Esto cambia la contraseña de {$record->email}. El usuario tendrá que usar la nueva en su próximo login.")
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('Nueva contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->rule(Password::default()),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(['password' => $data['new_password']]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Contraseña actualizada')
                            ->body("La contraseña de {$record->email} fue reseteada.")
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record) => $record->id !== Auth::id())
                    ->modalDescription(fn (User $record) => "Vas a borrar al usuario {$record->email}. Esta acción no se puede deshacer (usa 'desactivar' si prefieres mantener el historial)."),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
