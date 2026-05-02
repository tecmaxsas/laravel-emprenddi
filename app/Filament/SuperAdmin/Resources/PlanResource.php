<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\PlanResource\Pages;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Planes';

    protected static ?string $modelLabel = 'Plan';

    protected static ?string $pluralModelLabel = 'Planes';

    protected static ?string $navigationGroup = 'Suscripciones';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información general')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug (URL)')
                        ->helperText('Se autogenera del nombre si lo dejas vacío.')
                        ->maxLength(100)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Precio y facturación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('price')
                        ->label('Precio')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->default(0)
                        ->required(),

                    Forms\Components\Select::make('billing_cycle')
                        ->label('Ciclo de facturación')
                        ->options([
                            'monthly' => 'Mensual',
                            'yearly' => 'Anual',
                            'custom' => 'Personalizado',
                        ])
                        ->default('monthly')
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD'])
                        ->default('COP')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('trial_days')
                        ->label('Días de prueba')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->default(0)
                        ->required(),
                ]),

            Forms\Components\Section::make('Funcionalidades incluidas')
                ->description('Marca los módulos a los que tendrá acceso una compañía con este plan.')
                ->schema([
                    Forms\Components\CheckboxList::make('features')
                        ->label('')
                        ->options(Plan::FEATURE_KEYS)
                        ->columns(2),
                ]),

            Forms\Components\Section::make('Límites')
                ->description('Deja un campo VACÍO para significar ilimitado.')
                ->columns(3)
                ->schema(
                    collect(Plan::LIMIT_KEYS)->map(fn ($label, $key) => Forms\Components\TextInput::make("limits.{$key}")
                        ->label($label)
                        ->numeric()
                        ->minValue(0)
                        ->placeholder('Ilimitado'))
                    ->values()
                    ->all()
                ),

            Forms\Components\Section::make('Visibilidad')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->label('Activo')
                        ->helperText('Si está apagado, no se puede asignar a nuevas suscripciones.')
                        ->default(true),

                    Forms\Components\Toggle::make('is_public')
                        ->label('Público')
                        ->helperText('Visible para nuevos registros y página de planes.')
                        ->default(true),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Precio')
                    ->money(fn (Plan $record) => $record->currency)
                    ->sortable(),

                Tables\Columns\TextColumn::make('billing_cycle')
                    ->label('Ciclo')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'monthly' => 'Mensual',
                        'yearly' => 'Anual',
                        'custom' => 'Personalizado',
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('trial_days')
                    ->label('Trial (días)')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('features')
                    ->label('Módulos')
                    ->formatStateUsing(fn (Plan $record) => count($record->features ?? []))
                    ->alignCenter()
                    ->tooltip(fn (Plan $record) => collect($record->features ?? [])
                        ->map(fn ($k) => Plan::FEATURE_KEYS[$k] ?? $k)
                        ->join(', ') ?: '—'),

                Tables\Columns\TextColumn::make('subscriptions_count')
                    ->label('Suscriptores')
                    ->counts('subscriptions')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean(),
                Tables\Columns\IconColumn::make('is_public')->label('Público')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Activo'),
                Tables\Filters\TernaryFilter::make('is_public')->label('Público'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
