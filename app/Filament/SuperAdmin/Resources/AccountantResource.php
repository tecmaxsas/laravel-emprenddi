<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\AccountantResource\Pages;
use App\Filament\SuperAdmin\Resources\AccountantResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contadores externos del portal. Listado para que el superadmin
 * apruebe / suspenda y administre las empresas que cada uno lleva.
 */
class AccountantResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Contadores';

    protected static ?string $modelLabel = 'Contador';

    protected static ?string $pluralModelLabel = 'Contadores';

    protected static ?string $navigationGroup = 'Portal Contador';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_accountant_portal', true);
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(120),

            Forms\Components\TextInput::make('last_name')
                ->label('Apellido')
                ->maxLength(120),

            Forms\Components\TextInput::make('email')
                ->label('Correo')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(120),

            Forms\Components\TextInput::make('password')
                ->label('Contraseña')
                ->password()
                ->revealable()
                ->minLength(8)
                ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create')
                ->helperText('Déjalo vacío al editar para mantener la contraseña actual.'),

            Forms\Components\Toggle::make('active')
                ->label('Activo (puede ingresar al portal)')
                ->default(true)
                ->inline(false),

            Forms\Components\Hidden::make('is_accountant_portal')->default(true),
            Forms\Components\Hidden::make('is_super_admin')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->state(fn (User $u) => $u->fullName())
                    ->searchable(['name', 'last_name'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('managed_companies_count')
                    ->label('Empresas')
                    ->counts('managedCompanies')
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Último ingreso')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('Nunca')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
            ])
            ->actions([
                Tables\Actions\Action::make('manageCompanies')
                    ->label('Empresas')
                    ->icon('heroicon-o-building-office-2')
                    ->color('info')
                    ->tooltip('Ver el contador y vincular/desvincular sus empresas')
                    ->url(fn (User $u) => static::getUrl('view', ['record' => $u])),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $u) => $u->active ? 'Suspender' : 'Reactivar')
                    ->icon(fn (User $u) => $u->active ? 'heroicon-o-no-symbol' : 'heroicon-o-check')
                    ->color(fn (User $u) => $u->active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (User $u) => $u->update(['active' => ! $u->active])),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ManagedCompaniesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountants::route('/'),
            'create' => Pages\CreateAccountant::route('/create'),
            'view' => Pages\ViewAccountant::route('/{record}'),
            'edit' => Pages\EditAccountant::route('/{record}/edit'),
        ];
    }
}
