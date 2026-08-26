<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\RoleResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Services\Auth\PermissionsCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'roles.view'; }
    protected static function managePermission(): string { return 'roles.manage'; }

    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Roles y Permisos';

    protected static ?string $modelLabel = 'Rol';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?string $navigationGroup = 'Gestión de usuarios';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        $sections = collect(PermissionsCatalog::groups())->map(function (array $perms, string $group) {
            return Forms\Components\Section::make($group)
                ->columns(2)
                ->collapsible()
                ->schema([
                    Forms\Components\CheckboxList::make("perms_{$group}")
                        ->label('')
                        ->options($perms)
                        ->columns(2)
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\CheckboxList $component, $state, $record) use ($perms) {
                            if ($record) {
                                $current = $record->permissions->pluck('name')->all();
                                $component->state(array_values(array_intersect(array_keys($perms), $current)));
                            }
                        }),
                ]);
        })->values()->all();

        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre del rol')
                        ->required()
                        ->maxLength(50)
                        ->disabled(fn ($record) => $record && in_array($record->name, ['admin'], true))
                        ->dehydrated(fn ($state) => filled($state))
                        ->unique(ignoreRecord: true)
                        ->helperText('El rol "admin" es del sistema y no se puede renombrar.'),
                    Forms\Components\Hidden::make('guard_name')->default('web'),
                ]),

            Forms\Components\Section::make('Permisos asignados')
                ->description('Marca cada permiso que este rol concede a sus usuarios. Los cambios aplican a todos los usuarios con este rol.')
                ->schema($sections),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Rol')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->badge(),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permisos')
                    ->counts('permissions')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->date('Y-m-d')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $record) => ! in_array($record->name, ['admin'], true))
                    ->modalDescription(fn (Role $record) => "Borrar el rol '{$record->name}' lo retira de todos los usuarios que lo tengan."),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
