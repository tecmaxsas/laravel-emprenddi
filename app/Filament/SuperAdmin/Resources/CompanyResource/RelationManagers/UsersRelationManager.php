<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;

use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;

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

                // Se puede entrar con correo O con nombre de usuario. Sin esta
                // columna, soporte no sabia con que identificador entra el
                // cliente, y una llamada de «no puedo entrar» se iba en
                // adivinar.
                Tables\Columns\TextColumn::make('username')
                    ->label('Usuario')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('— solo entra por correo —'),
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
                        Forms\Components\TextInput::make('username')
                            ->label('Nombre de usuario (opcional)')
                            ->maxLength(50)
                            ->helperText('Sirve para entrar sin escribir el correo. Se guarda en mayúsculas y no puede tener forma de correo.')
                            ->rule('not_regex:/@/'),

                        Forms\Components\Toggle::make('generate_random')
                            ->label('Generar contraseña aleatoria')->default(true)->live(),
                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña')->password()->revealable()->minLength(8)
                            ->required(fn (Forms\Get $get) => ! $get('generate_random'))
                            ->visible(fn (Forms\Get $get) => ! $get('generate_random'))
                            ->helperText('Mínimo 8 caracteres.'),
                        Forms\Components\CheckboxList::make('roles')
                            ->label('Roles')
                            // Solo los de ESTA empresa, y por id: el nombre
                            // esta repetido entre compañias.
                            ->options(fn () => Role::query()
                                ->where('company_id', $this->getOwnerRecord()->id)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
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
                            'username' => self::normalizarUsuario($data['username'] ?? null),
                            'password' => Hash::make($password),
                            'active' => (bool) ($data['active'] ?? true),
                        ]);
                        $user->syncRoles($this->rolesDeLaEmpresa($data['roles'] ?? []));

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
                        'username' => $record->username,
                        // Por id, para que case con las opciones del
                        // checkbox: si se llenara con nombres, ninguna quedaria
                        // marcada.
                        'roles' => $record->roles->pluck('id')->all(),
                        'active' => $record->active,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('name')->label('Nombres')->required()->maxLength(150),
                        Forms\Components\TextInput::make('last_name')->label('Apellidos')->maxLength(150),
                        Forms\Components\TextInput::make('email')->label('Email')->required()->email()->maxLength(150),
                        Forms\Components\TextInput::make('username')
                            ->label('Nombre de usuario (opcional)')
                            ->maxLength(50)
                            ->helperText('Con esto el cliente puede entrar sin escribir el correo.')
                            ->rule('not_regex:/@/'),
                        Forms\Components\CheckboxList::make('roles')
                            ->label('Roles')
                            ->options(fn () => Role::query()
                                ->where('company_id', $this->getOwnerRecord()->id)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
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
                            'username' => self::normalizarUsuario($data['username'] ?? null),
                            'active' => (bool) ($data['active'] ?? true),
                        ]);
                        $record->syncRoles($this->rolesDeLaEmpresa($data['roles'] ?? []));

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

                        // La clave en su propia linea y en monoespaciado. Antes
                        // iba dentro de un parrafo, y los `\n` de un cuerpo de
                        // notificacion se renderizan como espacios: quedaba
                        // «...para x@y.com: kuremasi42 Comparte por canal...»,
                        // de donde es facil copiar un espacio o un punto de mas
                        // y concluir que el cambio no sirvio.
                        $cuerpo = '<div style="line-height:1.6;">'
                            .'<div>Nueva contraseña de <b>'.e($record->email).'</b>:</div>'
                            .'<div style="font-family:ui-monospace,monospace; font-size:17px; font-weight:700;'
                            .' background:#f3f4f6; color:#111827; padding:6px 10px; border-radius:6px;'
                            .' margin:6px 0; letter-spacing:.5px; user-select:all;">'
                            .e($newPassword).'</div>'
                            .'<div style="font-size:12px;">Compártela por un canal seguro. Es la única vez que se muestra.</div>';

                        // Cambiar la clave no sirve de nada si el usuario no va
                        // a poder entrar igual. El login responde lo mismo en
                        // los cuatro casos —«credenciales incorrectas»—, asi
                        // que soporte concluia que el reseteo no funcionaba.
                        $impedimentos = self::porQueNoPodraEntrar($record);

                        if ($impedimentos !== []) {
                            $cuerpo .= '<div style="margin-top:10px; padding:8px 10px; background:#fef2f2;'
                                .' border-left:3px solid #dc2626; color:#7f1d1d; font-size:12px; line-height:1.5;">'
                                .'<b>Ojo: con la clave nueva todavía no va a poder entrar.</b><ul style="margin:4px 0 0 16px;">'
                                .implode('', array_map(fn ($m) => '<li>'.e($m).'</li>', $impedimentos))
                                .'</ul></div>';
                        }

                        $cuerpo .= '</div>';

                        Notification::make()
                            ->title('Contraseña actualizada')
                            ->body(new HtmlString($cuerpo))
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
     * Lo que va a seguir impidiendo el login aunque la clave sea correcta.
     *
     * `canAccessPanel()` revisa cuatro cosas y el formulario de login responde
     * lo mismo en todas: «credenciales incorrectas». Desde soporte eso se lee
     * como «el reseteo no funciona», y la causa real —una suscripcion
     * vencida— no aparece por ningun lado.
     *
     * @return list<string>
     */
    private static function porQueNoPodraEntrar(User $usuario): array
    {
        $motivos = [];
        $empresa = $usuario->company;

        if (! $usuario->active) {
            $motivos[] = 'El usuario está inactivo. Actívalo con el botón «Activar».';
        }

        if ($empresa && ! $empresa->active) {
            $motivos[] = 'La empresa está inactiva.';
        }

        if ($empresa && ! $empresa->hasActiveSubscription()) {
            $motivos[] = 'La empresa no tiene suscripción activa.';
        }

        return $motivos;
    }

    /**
     * El nombre de usuario, normalizado.
     *
     * Vacio se guarda como null y no como cadena vacia: la columna es unica, y
     * dos usuarios con '' chocarian entre si.
     */
    private static function normalizarUsuario(?string $valor): ?string
    {
        $valor = strtoupper(trim((string) $valor));

        return $valor === '' ? null : $valor;
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

    /**
     * Los roles de esta empresa a partir de los ids del formulario.
     *
     * Se devuelven modelos y no ids sueltos: Spatie interpreta una cadena
     * numerica como el NOMBRE de un rol, y con los nombres repetidos entre
     * empresas podria enganchar el de otra.
     *
     * @param  list<int|string>  $ids
     * @return Collection<int, Role>
     */
    protected function rolesDeLaEmpresa(array $ids)
    {
        return Role::query()
            ->where('company_id', $this->getOwnerRecord()->id)
            ->whereIn('id', $ids)
            ->get();
    }
}
