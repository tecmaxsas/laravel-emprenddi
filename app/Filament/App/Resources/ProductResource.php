<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ProductResource\Pages;
use App\Models\Account;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tax;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static ?string $navigationGroup = 'Catálogo';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('product')->tabs([

                Forms\Components\Tabs\Tab::make('General')
                    ->icon('heroicon-o-information-circle')
                    ->schema([
                        Forms\Components\Group::make()->columns(3)->schema([
                            Forms\Components\TextInput::make('code')
                                ->label('Código (SKU)')
                                ->required()
                                ->maxLength(50)
                                ->unique(
                                    ignoreRecord: true,
                                    modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()->company_id)
                                ),

                            Forms\Components\TextInput::make('barcode')
                                ->label('Código de barras')
                                ->maxLength(50)
                                ->placeholder('EAN13/UPC'),

                            Forms\Components\TextInput::make('brand')
                                ->label('Marca')
                                ->maxLength(100),

                            Forms\Components\TextInput::make('name')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(200)
                                ->columnSpan(3),

                            Forms\Components\Textarea::make('description')
                                ->label('Descripción')
                                ->rows(2)
                                ->columnSpan(3),

                            Forms\Components\Select::make('category_id')
                                ->label('Categoría')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Category::query()
                                    ->where('name', 'ilike', "%{$search}%")
                                    ->orderBy('name')
                                    ->limit(20)
                                    ->pluck('name', 'id')
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => Category::find($value)?->fullName())
                                ->placeholder('— sin categoría —'),

                            Forms\Components\Select::make('type')
                                ->label('Tipo')
                                ->options(Product::TYPES)
                                ->default('good')
                                ->required()
                                ->live()
                                ->native(false)
                                ->helperText(fn ($state) => match ($state) {
                                    'good' => 'Bien físico que se compra y se vende. Controla stock por sede.',
                                    'service' => 'Servicio intangible. No controla inventario.',
                                    'kit' => 'Combo de productos. Al venderse desglosa componentes.',
                                    'consumable' => 'Insumo de uso interno (no se vende a clientes).',
                                    'variable' => 'Padre con N variantes (talla/color). Las variantes son las que se venden.',
                                    default => null,
                                })
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if ($state === 'service') {
                                        $set('track_inventory', false);
                                    }
                                    if ($state === 'variable') {
                                        $set('is_sellable', false);
                                        $set('is_purchasable', false);
                                        $set('track_inventory', false);
                                    }
                                }),

                            Forms\Components\Select::make('unit_of_measure')
                                ->label('Unidad de medida')
                                ->options(Product::COMMON_UNITS)
                                ->default('unit')
                                ->required()
                                ->searchable()
                                ->native(false),
                        ]),

                        Forms\Components\Section::make('Comportamiento')
                            ->columns(4)
                            ->schema([
                                Forms\Components\Toggle::make('track_inventory')
                                    ->label('Controla inventario')
                                    ->default(true)
                                    ->helperText('Off para servicios.'),

                                Forms\Components\Toggle::make('is_purchasable')
                                    ->label('Se compra')
                                    ->default(true),

                                Forms\Components\Toggle::make('is_sellable')
                                    ->label('Se vende')
                                    ->default(true),

                                Forms\Components\Toggle::make('active')
                                    ->label('Activo')
                                    ->default(true),
                            ]),
                    ]),

                Forms\Components\Tabs\Tab::make('Precios e impuestos')
                    ->icon('heroicon-o-currency-dollar')
                    ->schema([
                        Forms\Components\Placeholder::make('variable_hint_prices')
                            ->label('')
                            ->content('💡 Para productos variables, estos valores son DEFAULTS que las variantes heredan al crearse. Cada variante puede tener su propio override.')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'variable'),
                        Forms\Components\Group::make()->columns(3)->schema([
                            Forms\Components\TextInput::make('default_purchase_price')
                                ->label('Precio compra (default)')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->default(0),

                            Forms\Components\TextInput::make('default_sale_price')
                                ->label('Precio venta (default)')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->default(0),

                            Forms\Components\TextInput::make('min_sale_price')
                                ->label('Precio mínimo de venta')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->helperText('No permite vender por debajo (si se define).'),

                            Forms\Components\Select::make('default_purchase_tax_id')
                                ->label('Impuesto compra (default)')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Tax::query()
                                    ->where('is_active', true)
                                    ->whereIn('applies_to', ['purchase', 'both'])
                                    ->where(function ($q) use ($search) {
                                        $q->where('name', 'ilike', "%{$search}%")
                                          ->orWhere('code', 'ilike', "%{$search}%");
                                    })
                                    ->orderBy('name')
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (Tax $t) => [$t->id => "{$t->code} — {$t->name}"])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => Tax::find($value)
                                    ? Tax::find($value)->code.' — '.Tax::find($value)->name
                                    : null),

                            Forms\Components\Select::make('default_sale_tax_id')
                                ->label('Impuesto venta (default)')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Tax::query()
                                    ->where('is_active', true)
                                    ->whereIn('applies_to', ['sale', 'both'])
                                    ->where(function ($q) use ($search) {
                                        $q->where('name', 'ilike', "%{$search}%")
                                          ->orWhere('code', 'ilike', "%{$search}%");
                                    })
                                    ->orderBy('name')
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (Tax $t) => [$t->id => "{$t->code} — {$t->name}"])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => Tax::find($value)
                                    ? Tax::find($value)->code.' — '.Tax::find($value)->name
                                    : null),
                        ]),
                    ]),

                Forms\Components\Tabs\Tab::make('Cuentas contables')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Placeholder::make('variable_hint_accounts')
                            ->label('')
                            ->content('💡 Para productos variables, estas cuentas son DEFAULTS que las variantes heredan automáticamente al crearse.')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'variable'),
                        Forms\Components\Group::make()->columns(1)->schema([
                            self::accountSelect(
                                'inventory_account_id',
                                'Cuenta de inventario',
                                'Donde se registra el stock contablemente. Típico: 1435 — Mercancías no fabricadas.'
                            ),
                            self::accountSelect(
                                'sale_account_id',
                                'Cuenta de ingreso por venta',
                                'Típico: 4135 — Comercio al por mayor y al por menor.'
                            ),
                            self::accountSelect(
                                'cost_account_id',
                                'Cuenta de costo de venta',
                                'Típico: 6135 — Costo comercio al por mayor y al por menor.'
                            ),
                        ]),
                    ]),

                Forms\Components\Tabs\Tab::make('Sedes / Inventario')
                    ->icon('heroicon-o-building-storefront')
                    ->schema([
                        Forms\Components\Placeholder::make('variable_hint_locations')
                            ->label('')
                            ->content('💡 Para productos variables, las sedes definidas aquí son DEFAULTS para las variantes. Cada variante maneja su propio inventario por sede de forma independiente.')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'variable'),
                        Forms\Components\Repeater::make('productLocations')
                            ->relationship()
                            ->label('Disponibilidad por sede')
                            ->helperText('Añade las sedes donde este producto está disponible. Cada sede puede tener su propio inventario, precios y stock mínimo.')
                            ->schema([
                                Forms\Components\Select::make('location_id')
                                    ->label('Sede')
                                    ->required()
                                    ->options(fn () => Location::query()
                                        ->where('active', true)
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn (Location $l) => [$l->id => $l->fullName()])
                                        ->all())
                                    ->native(false)
                                    ->columnSpan(3),

                                Forms\Components\Toggle::make('active')
                                    ->label('Activo')
                                    ->default(true)
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('min_stock')
                                    ->label('Stock mín.')
                                    ->numeric()
                                    ->minValue(0)
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('max_stock')
                                    ->label('Stock máx.')
                                    ->numeric()
                                    ->minValue(0)
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('reorder_point')
                                    ->label('Punto reorden')
                                    ->numeric()
                                    ->minValue(0)
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('override_sale_price')
                                    ->label('Precio venta (override)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('$')
                                    ->placeholder('— usa default —')
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('override_purchase_price')
                                    ->label('Precio compra (override)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('$')
                                    ->placeholder('— usa default —')
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('shelf_location')
                                    ->label('Ubicación física (estante)')
                                    ->maxLength(50)
                                    ->placeholder('A-12-3')
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('initial_quantity')
                                    ->label('Cantidad inicial')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->dehydrated(false)
                                    ->visibleOn('create')
                                    ->helperText('Stock con el que arranca esta sede.')
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('initial_unit_cost')
                                    ->label('Costo unitario inicial')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('$')
                                    ->dehydrated(false)
                                    ->visibleOn('create')
                                    ->helperText('Si se deja vacío usa el precio de compra default.')
                                    ->columnSpan(6),

                                Forms\Components\Placeholder::make('current_stock_display')
                                    ->label('Stock actual')
                                    ->visibleOn('edit')
                                    ->content(function (Forms\Get $get) {
                                        $locationId = $get('location_id');
                                        $productId = $this->record?->id;
                                        if (! $locationId || ! $productId) {
                                            return '—';
                                        }
                                        $stock = app(\App\Services\Inventory\InventoryEngine::class)
                                            ->currentStock($productId, $locationId);
                                        $cost = app(\App\Services\Inventory\InventoryEngine::class)
                                            ->currentAvgCost($productId, $locationId);

                                        return sprintf(
                                            '%s unidades · costo prom. $%s',
                                            number_format($stock, 2),
                                            number_format($cost, 2),
                                        );
                                    })
                                    ->columnSpan(12),
                            ])
                            ->columns(12)
                            ->addActionLabel('+ Añadir sede')
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => isset($state['location_id'])
                                ? Location::find($state['location_id'])?->fullName()
                                : 'Nueva sede'),
                    ]),
            ])->columnSpanFull(),
        ]);
    }

    private static function accountSelect(string $name, string $label, ?string $help = null): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label($label)
            ->helperText($help)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Account::query()
                ->where('accepts_movements', true)
                ->where('active', true)
                ->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'ilike', "%{$search}%");
                })
                ->orderBy('code')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                ->all())
            ->getOptionLabelUsing(fn ($value) => Account::find($value)
                ? Account::find($value)->code.' — '.Account::find($value)->name
                : null);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (Product $record) {
                        $parts = [];
                        if ($record->barcode) {
                            $parts[] = "Barcode: {$record->barcode}";
                        }
                        if ($record->isVariant() && $record->attributesLabel()) {
                            $parts[] = $record->attributesLabel();
                        }
                        if ($record->isVariable()) {
                            $parts[] = $record->variants()->count().' variantes';
                        }

                        return $parts ? implode(' · ', $parts) : null;
                    }),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Categoría')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => Product::TYPES[$state] ?? $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('default_sale_price')
                    ->label('Precio venta')
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('default_purchase_price')
                    ->label('Precio compra')
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('locations_count')
                    ->label('Sedes')
                    ->counts('locations')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('track_inventory')
                    ->label('Inv.')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\Filter::make('hide_variants')
                    ->label('Ocultar variantes (ver solo padres y simples)')
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNull('parent_product_id')),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(Product::TYPES),
                Tables\Filters\TernaryFilter::make('is_sellable')->label('Se vende'),
                Tables\Filters\TernaryFilter::make('is_purchasable')->label('Se compra'),
                Tables\Filters\TernaryFilter::make('track_inventory')->label('Controla inventario'),
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ProductResource\RelationManagers\VariantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
