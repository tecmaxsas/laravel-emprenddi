<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\CompanyResource\Pages;
use App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers;
use App\Models\Account;
use App\Models\Company;
use App\Services\Accounting\PucProvisioner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Compañías';

    protected static ?string $modelLabel = 'Compañía';

    protected static ?string $pluralModelLabel = 'Compañías';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre comercial')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('legal_name')
                        ->label('Razón social')
                        ->maxLength(255),

                    Forms\Components\Select::make('document_type')
                        ->label('Tipo de documento')
                        ->options([
                            'nit' => 'NIT',
                            'cc' => 'CC',
                            'ce' => 'CE',
                            'pasaporte' => 'Pasaporte',
                            'rut' => 'RUT',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('nit')
                        ->label('Número')
                        ->required()
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('dv')->label('DV')->maxLength(1),

                    Forms\Components\Select::make('organization_type')
                        ->label('Tipo de organización')
                        ->options([
                            'juridica' => 'Persona Jurídica',
                            'natural' => 'Persona Natural',
                        ])
                        ->required(),
                ]),

            Forms\Components\Section::make('Configuración fiscal y operativa')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('regime_type')
                        ->label('Régimen')
                        ->options([
                            'comun' => 'Responsable de IVA',
                            'no_responsable_iva' => 'No Responsable de IVA',
                            'gran_contribuyente' => 'Gran Contribuyente',
                            'simplificado' => 'Simplificado',
                        ])
                        ->required(),

                    Forms\Components\Select::make('accounting_method')
                        ->label('Contabilidad')
                        ->options([
                            'niif_pymes' => 'NIIF Pymes',
                            'niif_full' => 'NIIF Full',
                        ])
                        ->required(),

                    Forms\Components\Select::make('inventory_method')
                        ->label('Inventario')
                        ->options([
                            'weighted_average' => 'Promedio Ponderado',
                            'fifo' => 'FIFO',
                            'lifo' => 'LIFO',
                        ])
                        ->required(),

                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD'])
                        ->required(),
                ]),

            Forms\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('email')->email(),
                    Forms\Components\TextInput::make('phone')->tel(),
                    Forms\Components\TextInput::make('address')->columnSpanFull(),
                    Forms\Components\TextInput::make('city'),
                    Forms\Components\TextInput::make('department'),
                ]),

            Forms\Components\Section::make('Estado')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('active')->label('Activa'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Company $r) => strtoupper($r->document_type).' '.$r->fullNit()),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->searchable(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('activeSubscription.plan.name')
                    ->label('Plan actual')
                    ->badge()
                    ->placeholder('Sin suscripción'),

                Tables\Columns\TextColumn::make('activeSubscription.status')
                    ->label('Estado sub.')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'trial' => 'warning',
                        'active' => 'success',
                        'past_due' => 'warning',
                        'cancelled', 'expired' => 'gray',
                        'suspended' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('activeSubscription.ends_at')
                    ->label('Vence')
                    ->date('Y-m-d')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrada')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa'),
                Tables\Filters\Filter::make('without_subscription')
                    ->label('Sin suscripción activa')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('activeSubscription')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('provisionPuc')
                    ->label('Provisionar PUC')
                    ->icon('heroicon-o-list-bullet')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Provisionar Plan Único de Cuentas')
                    ->modalDescription(fn (Company $record) => sprintf(
                        'La empresa "%s" tiene actualmente %d cuentas. Esto añadirá el PUC estándar Colombia (~280 cuentas) sin duplicar las que ya existan.',
                        $record->name,
                        Account::withoutGlobalScopes()->where('company_id', $record->id)->count()
                    ))
                    ->modalSubmitActionLabel('Provisionar')
                    ->action(function (Company $record) {
                        $created = app(PucProvisioner::class)->provision($record);

                        Notification::make()
                            ->success()
                            ->title('PUC provisionado')
                            ->body("Se crearon {$created} cuentas nuevas en {$record->name}.")
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SubscriptionsRelationManager::class,
            RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
