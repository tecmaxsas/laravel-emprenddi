<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PromotionResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\PromotionsSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PromotionResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'promotions.view'; }
    protected static function managePermission(): string { return 'promotions.manage'; }

    protected static ?string $model = Promotion::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Promociones';
    protected static ?string $modelLabel = 'Promoción';
    protected static ?string $pluralModelLabel = 'Promociones';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 80;

    /**
     * El resource solo aparece si el modulo esta activo en settings.
     * Si esta off: invisible en sidebar y URL devuelve 403.
     */
    public static function canAccess(): bool
    {
        if (! PromotionsSettings::moduleActive()) return false;
        return parent::canAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('promotion_tabs')
                ->columnSpanFull()
                ->tabs([
                    // ============================================
                    // TAB 1: Datos básicos y tipo
                    // ============================================
                    Forms\Components\Tabs\Tab::make('General')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(150)
                                ->helperText('Nombre visible al cajero y al cliente (ej. "Descuento 10% Bebidas", "2x1 en Hamburguesas").'),

                            Forms\Components\Textarea::make('description')
                                ->label('Descripción interna')
                                ->rows(2)
                                ->columnSpanFull()
                                ->helperText('Notas para el equipo. No se muestra al cliente.'),

                            Forms\Components\Toggle::make('active')
                                ->label('Activa')
                                ->default(true)
                                ->helperText('Si la desactivas, deja de aplicarse aunque siga en vigencia.'),

                            Forms\Components\Select::make('type')
                                ->label('Tipo de promoción')
                                ->required()
                                ->live()
                                ->options(self::availableTypes())
                                ->helperText('Solo aparecen los tipos habilitados en Configuraciones → Promociones.'),

                            // Codigo de cupon (solo si requires_code = true)
                            Forms\Components\Toggle::make('requires_code')
                                ->label('Requiere código (cupón)')
                                ->live()
                                ->default(false)
                                ->visible(fn () => PromotionsSettings::isEnabled('coupons'))
                                ->helperText('Si está activo, el cliente debe ingresar el código en POS para aplicar el descuento. Si no, se aplica automáticamente cuando se cumplen las condiciones.'),

                            Forms\Components\TextInput::make('code')
                                ->label('Código del cupón')
                                ->required()
                                ->maxLength(50)
                                ->alphaDash()
                                ->visible(fn (Forms\Get $get) => $get('requires_code'))
                                ->helperText('Letras, números, guiones. Ej. BIENVENIDO10, NAVIDAD2026.')
                                ->placeholder('BIENVENIDO10'),
                        ])
                        ->columns(2),

                    // ============================================
                    // TAB 2: Descuento (config dinámica según type)
                    // ============================================
                    Forms\Components\Tabs\Tab::make('Descuento')
                        ->icon('heroicon-o-banknotes')
                        ->schema(self::discountFields()),

                    // ============================================
                    // TAB 3: Alcance — a qué productos aplica
                    // ============================================
                    Forms\Components\Tabs\Tab::make('Alcance')
                        ->icon('heroicon-o-squares-2x2')
                        ->schema([
                            Forms\Components\Radio::make('scope')
                                ->label('Aplica a')
                                ->required()
                                ->default(Promotion::SCOPE_ALL)
                                ->live()
                                ->options([
                                    Promotion::SCOPE_ALL => 'Todos los productos',
                                    Promotion::SCOPE_CATEGORIES => 'Categorías específicas',
                                    Promotion::SCOPE_PRODUCTS => 'Productos específicos',
                                ]),

                            Forms\Components\Select::make('scope_categories')
                                ->label('Categorías')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(fn () => Category::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                                ->visible(fn (Forms\Get $get) => $get('scope') === Promotion::SCOPE_CATEGORIES)
                                ->helperText('La promoción aplica a productos de estas categorías.'),

                            Forms\Components\Select::make('scope_products')
                                ->label('Productos')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(fn () => Product::query()
                                    ->where('active', true)
                                    ->orderBy('name')
                                    ->limit(500)
                                    ->pluck('name', 'id'))
                                ->visible(fn (Forms\Get $get) => $get('scope') === Promotion::SCOPE_PRODUCTS)
                                ->helperText('La promoción solo aplica a estos productos. Para más de 500 productos, mejor usa categorías.'),
                        ]),

                    // ============================================
                    // TAB 4: Condiciones (umbrales y modos servicio)
                    // ============================================
                    Forms\Components\Tabs\Tab::make('Condiciones')
                        ->icon('heroicon-o-funnel')
                        ->schema([
                            Forms\Components\Section::make('Umbrales del carrito')
                                ->description('La promoción solo aplica si el carrito cumple estos mínimos. Déjalos vacíos para aplicar siempre.')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('min_quantity')
                                        ->label('Cantidad mínima de unidades')
                                        ->numeric()
                                        ->minValue(0)
                                        ->placeholder('Sin mínimo')
                                        ->helperText('Cantidad total de items en el carrito (sumando los del alcance).'),

                                    Forms\Components\TextInput::make('min_amount')
                                        ->label('Monto mínimo del carrito')
                                        ->numeric()
                                        ->minValue(0)
                                        ->prefix('$')
                                        ->placeholder('Sin mínimo')
                                        ->helperText('Subtotal mínimo (sin IVA) para que la promoción aplique.'),
                                ]),

                            Forms\Components\Section::make('Modos de servicio (restaurante)')
                                ->description('Limita la promoción a ciertos modos de servicio. Si tu empresa no usa restaurante, déjalos todos activos.')
                                ->columns(3)
                                ->schema([
                                    Forms\Components\Toggle::make('applies_dine_in')->label('Comer aquí')->default(true)->inline(false),
                                    Forms\Components\Toggle::make('applies_takeaway')->label('Para llevar')->default(true)->inline(false),
                                    Forms\Components\Toggle::make('applies_delivery')->label('Domicilio')->default(true)->inline(false),
                                ]),

                            Forms\Components\Section::make('Límites de uso (cupones)')
                                ->description('Solo aplica si la promoción requiere código. Permite limitar abusos del cupón.')
                                ->columns(2)
                                ->visible(fn (Forms\Get $get) => $get('requires_code'))
                                ->schema([
                                    Forms\Components\TextInput::make('max_uses_total')
                                        ->label('Usos totales máximos')
                                        ->numeric()
                                        ->minValue(0)
                                        ->placeholder('Ilimitado')
                                        ->helperText('Cuántas veces en total puede usarse el cupón (todos los clientes).'),

                                    Forms\Components\TextInput::make('max_uses_per_customer')
                                        ->label('Usos por cliente')
                                        ->numeric()
                                        ->minValue(0)
                                        ->placeholder('Ilimitado')
                                        ->helperText('Cuántas veces puede usarlo el mismo cliente. Requiere identificar al cliente en la venta.'),
                                ]),
                        ]),

                    // ============================================
                    // TAB 5: Vigencia (fechas + happy hour)
                    // ============================================
                    Forms\Components\Tabs\Tab::make('Vigencia')
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Forms\Components\Section::make('Rango de fechas')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\DateTimePicker::make('valid_from')
                                        ->label('Vigente desde')
                                        ->placeholder('Sin fecha de inicio')
                                        ->helperText('Si lo dejas vacío, aplica desde el momento de creación.'),

                                    Forms\Components\DateTimePicker::make('valid_to')
                                        ->label('Vigente hasta')
                                        ->placeholder('Sin fecha de fin')
                                        ->helperText('Si lo dejas vacío, no expira automáticamente.'),
                                ]),

                            Forms\Components\Section::make('Happy Hour (días y horas)')
                                ->description('Limita la promoción a ciertos días de la semana y un rango horario. Útil para "20% off bebidas Lun-Vie 5-7 PM".')
                                ->visible(fn () => PromotionsSettings::isEnabled('happy_hour'))
                                ->schema([
                                    Forms\Components\CheckboxList::make('days_of_week')
                                        ->label('Días de la semana')
                                        ->options([
                                            'mon' => 'Lunes',
                                            'tue' => 'Martes',
                                            'wed' => 'Miércoles',
                                            'thu' => 'Jueves',
                                            'fri' => 'Viernes',
                                            'sat' => 'Sábado',
                                            'sun' => 'Domingo',
                                        ])
                                        ->columns(4)
                                        ->helperText('Sin selección = todos los días.'),

                                    Forms\Components\Grid::make(2)->schema([
                                        Forms\Components\TimePicker::make('hour_from')
                                            ->label('Desde la hora')
                                            ->seconds(false)
                                            ->placeholder('Todo el día'),

                                        Forms\Components\TimePicker::make('hour_to')
                                            ->label('Hasta la hora')
                                            ->seconds(false)
                                            ->placeholder('Todo el día'),
                                    ]),
                                ]),
                        ]),

                    // ============================================
                    // TAB 6: Comportamiento (apilar, prioridad)
                    // ============================================
                    Forms\Components\Tabs\Tab::make('Comportamiento')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->schema([
                            Forms\Components\Toggle::make('stackable')
                                ->label('Combinable con otras promociones')
                                ->default(false)
                                ->visible(fn () => PromotionsSettings::isEnabled('allow_stacking'))
                                ->helperText('Si está activa, puede aplicarse junto a otras promociones también marcadas como combinables. Si no, es exclusiva.'),

                            Forms\Components\TextInput::make('priority')
                                ->label('Prioridad')
                                ->numeric()
                                ->default(0)
                                ->helperText('Cuando hay varias promociones aplicables, se evalúan de mayor a menor prioridad. Útil para que las "exclusivas" más valiosas ganen.'),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    /**
     * Lista de tipos disponibles segun los toggles del modulo.
     */
    private static function availableTypes(): array
    {
        $out = [];
        if (PromotionsSettings::isEnabled('percentage'))   $out[Promotion::TYPE_PERCENTAGE]   = 'Descuento por porcentaje';
        if (PromotionsSettings::isEnabled('fixed_amount')) $out[Promotion::TYPE_FIXED_AMOUNT] = 'Descuento de monto fijo';
        if (PromotionsSettings::isEnabled('bogo'))         $out[Promotion::TYPE_BOGO]         = '2x1 / 3x2 (BOGO)';
        if (PromotionsSettings::isEnabled('volume_tier'))  $out[Promotion::TYPE_VOLUME_TIER]  = 'Volumen escalonado';
        if (PromotionsSettings::isEnabled('bundle'))       $out[Promotion::TYPE_BUNDLE]       = 'Combo / paquete';
        return $out;
    }

    /**
     * Campos del descuento — visibles segun el tipo seleccionado.
     */
    private static function discountFields(): array
    {
        return [
            // PORCENTAJE
            Forms\Components\TextInput::make('discount_value')
                ->label('Porcentaje de descuento')
                ->numeric()
                ->minValue(0.01)
                ->maxValue(100)
                ->step(0.01)
                ->suffix('%')
                ->required(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_PERCENTAGE)
                ->visible(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_PERCENTAGE)
                ->helperText('Ej. 10 = 10% off. Se calcula sobre los items del alcance.'),

            // MONTO FIJO
            Forms\Components\TextInput::make('discount_value')
                ->label('Monto del descuento')
                ->numeric()
                ->minValue(1)
                ->prefix('$')
                ->required(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_FIXED_AMOUNT)
                ->visible(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_FIXED_AMOUNT)
                ->helperText('Monto fijo a descontar del total. Si el carrito es menor que este monto, descuenta solo lo que cubra.'),

            // BOGO
            Forms\Components\Section::make('Configuración BOGO')
                ->visible(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_BOGO)
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('discount_data.buy_quantity')
                        ->label('Compra esta cantidad')
                        ->numeric()
                        ->minValue(2)
                        ->default(2)
                        ->required()
                        ->helperText('Cuántas unidades debe llevar el cliente para activar la promo.'),

                    Forms\Components\TextInput::make('discount_data.get_quantity')
                        ->label('Y se lleva GRATIS')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required()
                        ->helperText('Cuántas unidades adicionales recibe gratis.'),

                    Forms\Components\Radio::make('discount_data.free_item_strategy')
                        ->label('¿Cuál es la unidad gratis?')
                        ->default('cheapest')
                        ->columnSpanFull()
                        ->options([
                            'cheapest' => 'La más barata del carrito (recomendado)',
                            'same_product' => 'Otra unidad del mismo producto',
                        ])
                        ->helperText('Lo estándar es "más barata" — el cliente paga las costosas y recibe gratis la más económica.'),
                ]),

            // VOLUME TIER
            Forms\Components\Section::make('Niveles de volumen')
                ->description('Define escalones por cantidad. El sistema toma el % del nivel correspondiente a la cantidad comprada.')
                ->visible(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_VOLUME_TIER)
                ->schema([
                    Forms\Components\Repeater::make('discount_data.tiers')
                        ->label('Escalones')
                        ->defaultItems(2)
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make('min')
                                ->label('Desde (unid.)')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                            Forms\Components\TextInput::make('max')
                                ->label('Hasta (unid.)')
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('Sin tope')
                                ->helperText('Vacío = el ultimo escalón'),
                            Forms\Components\TextInput::make('percent')
                                ->label('% off')
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),
                        ])
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state) => sprintf(
                            '%s a %s unid. — %s%% off',
                            $state['min'] ?? '?',
                            $state['max'] ?? '∞',
                            $state['percent'] ?? '?',
                        )),
                ]),

            // BUNDLE
            Forms\Components\Section::make('Configuración del combo')
                ->description('Define los productos del combo y el precio final. El sistema reemplaza el subtotal de esos productos con bundle_price.')
                ->visible(fn (Forms\Get $get) => $get('type') === Promotion::TYPE_BUNDLE)
                ->schema([
                    Forms\Components\TextInput::make('discount_data.bundle_price')
                        ->label('Precio final del combo')
                        ->numeric()
                        ->minValue(1)
                        ->prefix('$')
                        ->required(),

                    Forms\Components\Repeater::make('discount_data.items')
                        ->label('Productos del combo')
                        ->defaultItems(2)
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Producto')
                                ->options(fn () => Product::query()
                                    ->where('active', true)
                                    ->orderBy('name')
                                    ->limit(500)
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required(),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cantidad')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                        ])
                        ->collapsible()
                        ->itemLabel(fn (array $state) => isset($state['product_id'])
                            ? Product::find($state['product_id'])?->name . ' × ' . ($state['quantity'] ?? 1)
                            : null,
                        ),
                ]),

            // Helper card al inicio si no se seleccionó tipo aun
            Forms\Components\Placeholder::make('select_type_hint')
                ->label('')
                ->visible(fn (Forms\Get $get) => empty($get('type')))
                ->content(new HtmlString(
                    '<div style="padding:16px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; color:#78350f;">'
                    .'<strong>👆 Primero elige un tipo de promoción</strong> en el tab General. '
                    .'Los campos del descuento dependen del tipo seleccionado.'
                    .'</div>'
                )),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->code ? '🎟️ Cupón: ' . $record->code : null),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Promotion::TYPE_PERCENTAGE => 'Porcentaje',
                        Promotion::TYPE_FIXED_AMOUNT => 'Monto fijo',
                        Promotion::TYPE_BOGO => 'BOGO (2x1)',
                        Promotion::TYPE_VOLUME_TIER => 'Volumen',
                        Promotion::TYPE_BUNDLE => 'Combo',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        Promotion::TYPE_PERCENTAGE => 'success',
                        Promotion::TYPE_FIXED_AMOUNT => 'info',
                        Promotion::TYPE_BOGO => 'warning',
                        Promotion::TYPE_VOLUME_TIER => 'primary',
                        Promotion::TYPE_BUNDLE => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('discount_summary')
                    ->label('Descuento')
                    ->state(fn ($record) => self::discountSummary($record)),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Aplica a')
                    ->formatStateUsing(fn (string $state, $record) => match ($state) {
                        Promotion::SCOPE_ALL => 'Todo',
                        Promotion::SCOPE_CATEGORIES => count($record->scope_categories ?? []) . ' categorías',
                        Promotion::SCOPE_PRODUCTS => count($record->scope_products ?? []) . ' productos',
                        default => $state,
                    })
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('valid_to')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Sin expiración')
                    ->color(fn ($record) => $record->valid_to && $record->valid_to->isPast() ? 'danger' : null),

                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Usos')
                    ->numeric()
                    ->sortable()
                    ->state(fn ($record) => $record->max_uses_total
                        ? $record->usage_count . ' / ' . $record->max_uses_total
                        : $record->usage_count),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Estado'),
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options([
                    Promotion::TYPE_PERCENTAGE => 'Porcentaje',
                    Promotion::TYPE_FIXED_AMOUNT => 'Monto fijo',
                    Promotion::TYPE_BOGO => 'BOGO',
                    Promotion::TYPE_VOLUME_TIER => 'Volumen',
                    Promotion::TYPE_BUNDLE => 'Combo',
                ]),
                Tables\Filters\TernaryFilter::make('requires_code')->label('Cupón'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('duplicate')
                    ->label('Duplicar')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function ($record) {
                        $new = $record->replicate();
                        $new->name = $record->name . ' (copia)';
                        $new->code = $record->code ? $record->code . '-COPY' : null;
                        $new->usage_count = 0;
                        $new->active = false;
                        $new->save();
                        \Filament\Notifications\Notification::make()
                            ->title('Promoción duplicada (desactivada por defecto)')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('priority', 'desc')
            ->emptyStateHeading('Sin promociones todavía')
            ->emptyStateDescription('Crea tu primera promoción para empezar a ofrecer descuentos automáticos en POS.')
            ->emptyStateIcon('heroicon-o-tag');
    }

    /** Resumen legible del descuento para la columna. */
    private static function discountSummary(Promotion $p): string
    {
        return match ($p->type) {
            Promotion::TYPE_PERCENTAGE => number_format((float) $p->discount_value, 0) . '% off',
            Promotion::TYPE_FIXED_AMOUNT => '$' . number_format((float) $p->discount_value, 0, ',', '.') . ' off',
            Promotion::TYPE_BOGO => ($p->discount_data['buy_quantity'] ?? '?')
                . 'x' . ($p->discount_data['get_quantity'] ?? '?'),
            Promotion::TYPE_VOLUME_TIER => count($p->discount_data['tiers'] ?? []) . ' escalones',
            Promotion::TYPE_BUNDLE => 'Combo $' . number_format((float) ($p->discount_data['bundle_price'] ?? 0), 0, ',', '.'),
            default => '—',
        };
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'create' => Pages\CreatePromotion::route('/create'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
