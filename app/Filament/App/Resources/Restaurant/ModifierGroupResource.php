<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\ModifierGroupResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Product;
use App\Models\Restaurant\ModifierGroup;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ModifierGroupResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.modifiers.manage'; }
    protected static function managePermission(): string { return 'restaurant.modifiers.manage'; }

    protected static ?string $model = ModifierGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Modificadores';

    protected static ?string $modelLabel = 'Grupo de modificadores';

    protected static ?string $pluralModelLabel = 'Grupos de modificadores';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 60;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        if (! \App\Support\RestaurantSettings::isEnabled('modifiers')) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Grupo')
                ->description('El grupo agrupa varias opciones. Ej: "Punto de cocción" agrupa Término medio, Bien cocido, Crudo.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre del grupo')
                        ->required()
                        ->maxLength(80)
                        ->placeholder('Punto de cocción, Extras, Quitar ingredientes')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('display_order')
                        ->label('Orden de aparición')
                        ->numeric()
                        ->default(0),

                    Forms\Components\TextInput::make('description')
                        ->label('Descripción (opcional)')
                        ->maxLength(200)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('min_select')
                        ->label('Mínimo de opciones')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->helperText('0 = opcional'),

                    Forms\Components\TextInput::make('max_select')
                        ->label('Máximo de opciones')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->helperText('1 = radio, >1 = checkbox múltiple'),

                    Forms\Components\Toggle::make('required')
                        ->label('Obligatorio')
                        ->helperText('El cajero no puede continuar sin elegir.')
                        ->inline(false),

                    Forms\Components\Toggle::make('active')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false),
                ]),

            Forms\Components\Section::make('Opciones')
                ->description('Cada opción puede sumar (o restar) precio al producto.')
                ->schema([
                    Forms\Components\Repeater::make('modifiers')
                        ->label('')
                        ->relationship()
                        ->orderColumn('display_order')
                        ->reorderable()
                        ->columns(4)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(80)
                                ->placeholder('Extra queso, Sin cebolla')
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('price_delta')
                                ->label('Δ precio')
                                ->numeric()
                                ->default(0)
                                ->helperText('Negativo descuenta')
                                ->prefix('$'),

                            Forms\Components\Toggle::make('active')
                                ->label('Activo')
                                ->default(true)
                                ->inline(false),
                        ])
                        ->itemLabel(fn (array $state): ?string => ($state['name'] ?? null)
                            ? $state['name'].(($state['price_delta'] ?? 0) > 0 ? ' (+$'.number_format((float)$state['price_delta'], 0).')' : '')
                            : null),
                        // company_id se autocompleta via BelongsToCompany trait.
                ]),

            Forms\Components\Section::make('Productos asociados')
                ->description('Cuando agreguen al pedido uno de estos productos, aparecerá este grupo de modificadores.')
                ->schema([
                    Forms\Components\Select::make('products')
                        ->label('Productos que usan este grupo')
                        ->relationship('products', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->options(fn () => Product::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->where('is_sellable', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->weight('semibold')->searchable(),

                Tables\Columns\TextColumn::make('modifiers_count')
                    ->label('Opciones')
                    ->counts('modifiers')
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Productos')
                    ->counts('products')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('min_max')
                    ->label('Min/Max')
                    ->state(fn (ModifierGroup $record) => "{$record->min_select}/{$record->max_select}")
                    ->fontFamily('mono'),

                Tables\Columns\IconColumn::make('required')->label('Obligatorio')->boolean(),
                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
                Tables\Filters\TernaryFilter::make('required')->label('Obligatorio'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListModifierGroups::route('/'),
            'create' => Pages\CreateModifierGroup::route('/create'),
            'edit' => Pages\EditModifierGroup::route('/{record}/edit'),
        ];
    }
}
