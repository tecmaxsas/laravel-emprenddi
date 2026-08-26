<?php

namespace App\Filament\App\Resources\Restaurant;

use App\Filament\App\Resources\Restaurant\MenuResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Product;
use App\Models\Restaurant\Menu;
use App\Support\ModuleGate;
use App\Support\RestaurantSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MenuResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'restaurant.menu.manage'; }
    protected static function managePermission(): string { return 'restaurant.menu.manage'; }

    protected static ?string $model = Menu::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Carta QR';

    protected static ?string $modelLabel = 'Carta';

    protected static ?string $pluralModelLabel = 'Cartas';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 100;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        if (! RestaurantSettings::isEnabled('qr_menu')) return false;
        return (bool) auth()->user()?->can(static::viewPermission());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('menu_builder')->columnSpanFull()->tabs([

                // ============ IDENTIDAD ============
                Forms\Components\Tabs\Tab::make('Identidad')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\Section::make('Información básica')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre de la carta')
                                    ->required()
                                    ->maxLength(120)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, callable $set, ?string $context) => $context === 'create'
                                        ? $set('slug', Str::slug($state))
                                        : null),

                                Forms\Components\TextInput::make('slug')
                                    ->label('URL pública (slug)')
                                    ->required()
                                    ->maxLength(60)
                                    ->unique(ignoreRecord: true)
                                    ->prefix(rtrim(url('/menu/'), '/').'/')
                                    ->helperText('Solo letras, números y guiones. Ej: "mi-pizzeria"')
                                    ->rules(['regex:/^[a-z0-9-]+$/']),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('Subtítulo / eslogan')
                                    ->maxLength(200)
                                    ->placeholder('"Cocina italiana en casa"')
                                    ->columnSpanFull(),

                                Forms\Components\Toggle::make('show_prices')
                                    ->label('Mostrar precios al público')
                                    ->default(true)
                                    ->helperText('Si está OFF, la carta solo muestra nombre + descripción + foto.')
                                    ->inline(false),

                                Forms\Components\Toggle::make('active')
                                    ->label('Carta activa')
                                    ->default(true)
                                    ->helperText('Si está OFF, la URL pública responde 404.')
                                    ->inline(false),
                            ]),

                        Forms\Components\Section::make('Imágenes')
                            ->columns(2)
                            ->schema([
                                Forms\Components\FileUpload::make('logo_path')
                                    ->label('Logo')
                                    ->image()
                                    ->imageEditor()
                                    ->directory('menus/logos')
                                    ->maxSize(2048)
                                    ->helperText('PNG/JPG, máximo 2 MB. Se muestra arriba.'),

                                Forms\Components\FileUpload::make('header_image_path')
                                    ->label('Imagen de portada')
                                    ->image()
                                    ->imageEditor()
                                    ->directory('menus/headers')
                                    ->maxSize(4096)
                                    ->helperText('Banner superior (1200×600 px). Máx 4 MB.'),
                            ]),
                    ]),

                // ============ TEMA / DISEÑO ============
                Forms\Components\Tabs\Tab::make('Diseño')
                    ->icon('heroicon-o-paint-brush')
                    ->schema([
                        Forms\Components\Section::make('Colores')
                            ->description('Definí la paleta principal del menú.')
                            ->columns(2)
                            ->schema([
                                Forms\Components\ColorPicker::make('theme.primary_color')
                                    ->label('Color primario')
                                    ->default(Menu::DEFAULT_THEME['primary_color'])
                                    ->helperText('Botones, títulos de secciones, acentos fuertes.'),

                                Forms\Components\ColorPicker::make('theme.accent_color')
                                    ->label('Color de acento')
                                    ->default(Menu::DEFAULT_THEME['accent_color'])
                                    ->helperText('Badges, etiquetas (popular, nuevo).'),

                                Forms\Components\ColorPicker::make('theme.bg_color')
                                    ->label('Color de fondo')
                                    ->default(Menu::DEFAULT_THEME['bg_color']),

                                Forms\Components\ColorPicker::make('theme.text_color')
                                    ->label('Color de texto')
                                    ->default(Menu::DEFAULT_THEME['text_color']),

                                Forms\Components\ColorPicker::make('theme.muted_color')
                                    ->label('Color secundario (descripciones)')
                                    ->default(Menu::DEFAULT_THEME['muted_color']),
                            ]),

                        Forms\Components\Section::make('Tipografía y layout')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('theme.font_family')
                                    ->label('Fuente')
                                    ->options(Menu::FONT_FAMILIES)
                                    ->default(Menu::DEFAULT_THEME['font_family'])
                                    ->native(false)
                                    ->helperText('Se carga desde Google Fonts.'),

                                Forms\Components\Select::make('theme.layout')
                                    ->label('Layout de items')
                                    ->options([
                                        'list' => 'Lista (item por línea)',
                                        'grid' => 'Grid (cards en 2 columnas)',
                                    ])
                                    ->default(Menu::DEFAULT_THEME['layout'])
                                    ->native(false),

                                Forms\Components\Select::make('theme.item_image_size')
                                    ->label('Tamaño de imagen del item')
                                    ->options([
                                        'small' => 'Pequeño',
                                        'medium' => 'Medio',
                                        'large' => 'Grande',
                                    ])
                                    ->default(Menu::DEFAULT_THEME['item_image_size'])
                                    ->native(false),

                                Forms\Components\Select::make('theme.header_style')
                                    ->label('Estilo del header')
                                    ->options([
                                        'image' => 'Imagen de portada',
                                        'gradient' => 'Degradado de color primario',
                                        'solid' => 'Color sólido',
                                    ])
                                    ->default(Menu::DEFAULT_THEME['header_style'])
                                    ->native(false),

                                Forms\Components\Toggle::make('theme.show_descriptions')
                                    ->label('Mostrar descripciones')
                                    ->default(true)
                                    ->inline(false),

                                Forms\Components\Toggle::make('theme.show_images')
                                    ->label('Mostrar imágenes de items')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),

                // ============ SECCIONES E ITEMS ============
                Forms\Components\Tabs\Tab::make('Secciones e items')
                    ->icon('heroicon-o-bars-3-bottom-left')
                    ->schema([
                        Forms\Components\Repeater::make('sections')
                            ->label('')
                            ->relationship()
                            ->orderColumn('display_order')
                            ->reorderable()
                            ->collapsible()
                            ->collapsed()
                            ->cloneable()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Nueva sección')
                            ->addActionLabel('+ Agregar sección')
                            ->schema([
                                Forms\Components\Grid::make(2)->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->label('Nombre de la sección')
                                        ->required()
                                        ->placeholder('Entradas, Pizzas, Postres…')
                                        ->maxLength(100),

                                    Forms\Components\Toggle::make('active')
                                        ->label('Visible')
                                        ->default(true)
                                        ->inline(false),

                                    Forms\Components\Textarea::make('description')
                                        ->label('Descripción (opcional)')
                                        ->rows(2)
                                        ->maxLength(400)
                                        ->placeholder('Una breve intro de esta sección.')
                                        ->columnSpanFull(),
                                ]),

                                Forms\Components\Repeater::make('items')
                                    ->label('Items')
                                    ->relationship()
                                    ->orderColumn('display_order')
                                    ->reorderable()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['name']
                                        ? $state['name'].' — $'.number_format((float) ($state['price'] ?? 0), 0)
                                        : 'Nuevo item')
                                    ->addActionLabel('+ Agregar item')
                                    ->schema([
                                        Forms\Components\Grid::make(2)->schema([
                                            Forms\Components\TextInput::make('name')
                                                ->label('Nombre')
                                                ->required()
                                                ->maxLength(200),

                                            Forms\Components\TextInput::make('price')
                                                ->label('Precio')
                                                ->numeric()
                                                ->prefix('$')
                                                ->default(0)
                                                ->required(),

                                            Forms\Components\Textarea::make('description')
                                                ->label('Descripción')
                                                ->rows(2)
                                                ->maxLength(500)
                                                ->placeholder('Ingredientes, notas…')
                                                ->columnSpanFull(),

                                            Forms\Components\FileUpload::make('image_path')
                                                ->label('Imagen del plato')
                                                ->image()
                                                ->imageEditor()
                                                ->directory('menus/items')
                                                ->maxSize(2048),

                                            Forms\Components\Select::make('product_id')
                                                ->label('Vincular a producto (opcional)')
                                                ->searchable()
                                                ->getSearchResultsUsing(fn (string $search) => Product::query()
                                                    ->where('company_id', auth()->user()?->company_id)
                                                    ->where('active', true)
                                                    ->where('is_sellable', true)
                                                    ->where(function ($q) use ($search) {
                                                        $q->where('name', 'ilike', "%{$search}%")
                                                          ->orWhere('code', 'ilike', "%{$search}%");
                                                    })
                                                    ->limit(15)
                                                    ->get()
                                                    ->mapWithKeys(fn ($p) => [$p->id => $p->name.' (${'.number_format((float) $p->default_sale_price, 0).'})'])
                                                    ->all())
                                                ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                                                ->helperText('Si lo vinculas, podrías sincronizar precios después.'),

                                            Forms\Components\Select::make('tags')
                                                ->label('Etiquetas / badges')
                                                ->options(\App\Models\Restaurant\MenuItem::COMMON_TAGS)
                                                ->multiple()
                                                ->native(false)
                                                ->columnSpanFull(),

                                            Forms\Components\Toggle::make('active')
                                                ->label('Visible')
                                                ->default(true)
                                                ->inline(false)
                                                ->columnSpanFull(),
                                        ]),
                                    ]),
                            ]),
                    ]),

                // ============ COMPARTIR ============
                Forms\Components\Tabs\Tab::make('Compartir / QR')
                    ->icon('heroicon-o-share')
                    ->hiddenOn('create')
                    ->schema([
                        Forms\Components\Placeholder::make('public_url')
                            ->label('URL pública')
                            ->content(fn (?Menu $record) => $record
                                ? new \Illuminate\Support\HtmlString(
                                    '<div style="display:flex; gap:8px; align-items:center;">'
                                    .'<input type="text" readonly value="'.route('menu.public', ['slug' => $record->slug]).'" '
                                    .'onclick="this.select()" '
                                    .'style="flex:1; padding:8px 10px; border-radius:6px; border:1px solid #d1d5db; font-family:monospace; font-size:12px;" />'
                                    .'<a href="'.route('menu.public', ['slug' => $record->slug]).'" target="_blank" '
                                    .'style="padding:8px 14px; background:#3b82f6; color:white; border-radius:6px; text-decoration:none; font-weight:700; font-size:12px;">'
                                    .'Abrir →</a></div>'
                                )
                                : '— Guarda la carta primero —'),

                        Forms\Components\Placeholder::make('qr')
                            ->label('Código QR')
                            ->content(fn (?Menu $record) => $record
                                ? new \Illuminate\Support\HtmlString(sprintf(
                                    '<div style="display:flex; flex-direction:column; align-items:center; gap:10px;">'
                                    .'<img src="https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=%s" '
                                    .'alt="QR" style="border:1px solid #e5e7eb; padding:10px; border-radius:8px; background:white;" />'
                                    .'<a href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=%s&download=1" download="qr-menu.png" '
                                    .'style="padding:8px 16px; background:#10b981; color:white; border-radius:6px; text-decoration:none; font-weight:700; font-size:13px;">'
                                    .'📥 Descargar QR de alta resolución</a></div>',
                                    urlencode(route('menu.public', ['slug' => $record->slug])),
                                    urlencode(route('menu.public', ['slug' => $record->slug])),
                                ))
                                : ''),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->weight('semibold')->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('URL')
                    ->fontFamily('mono')
                    ->prefix('/menu/'),
                Tables\Columns\TextColumn::make('sections_count')
                    ->label('Secciones')
                    ->counts('sections')
                    ->alignCenter()->badge()->color('info'),
                Tables\Columns\IconColumn::make('show_prices')->label('Precios')->boolean(),
                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Ver carta')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Menu $record) => route('menu.public', ['slug' => $record->slug]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'create' => Pages\CreateMenu::route('/create'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }
}
