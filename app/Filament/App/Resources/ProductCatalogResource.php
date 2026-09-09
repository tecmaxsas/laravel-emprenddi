<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ProductCatalogResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Category;
use App\Models\ProductCatalog;
use App\Services\Catalog\CatalogProducts;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * El catálogo público de productos, desde el panel.
 *
 * Se apoya en los permisos de productos y no en unos propios: quien administra
 * el catálogo de productos es quien decide qué se le muestra al público. Un
 * permiso más solo habría añadido un paso de configuración que nadie pide.
 */
class ProductCatalogResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'products.view';
    }

    protected static function managePermission(): string
    {
        return 'products.manage';
    }

    protected static ?string $model = ProductCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Catálogo público';

    protected static ?string $modelLabel = 'Catálogo público';

    protected static ?string $pluralModelLabel = 'Catálogos públicos';

    protected static ?string $navigationGroup = 'Productos';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('catalogo')
                ->columnSpanFull()
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Enlace')
                        ->icon('heroicon-o-link')
                        ->schema(self::pestanaEnlace()),

                    Forms\Components\Tabs\Tab::make('Qué se muestra')
                        ->icon('heroicon-o-eye')
                        ->schema(self::pestanaContenido()),

                    Forms\Components\Tabs\Tab::make('Diseño')
                        ->icon('heroicon-o-paint-brush')
                        ->schema(self::pestanaDiseno()),

                    Forms\Components\Tabs\Tab::make('Contacto')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->schema(self::pestanaContacto()),
                ]),
        ]);
    }

    /** @return list<Forms\Components\Component> */
    protected static function pestanaEnlace(): array
    {
        return [
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre del catálogo')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Ej. Catálogo 2026')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Forms\Set $set, string $operation) => $operation === 'create'
                            ? $set('slug', Str::slug((string) $state))
                            : null)
                        ->helperText('Es el título grande que ve el cliente.'),

                    Forms\Components\TextInput::make('slug')
                        ->label('Dirección del enlace')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->prefix(rtrim(url('/catalogo/'), '/').'/')
                        ->rules(['regex:/^[a-z0-9-]+$/'])
                        ->helperText('Solo minúsculas, números y guiones. Es única en toda la plataforma, '
                            .'así que si está ocupada tendrás que elegir otra.'),

                    Forms\Components\TextInput::make('subtitle')
                        ->label('Frase de presentación')
                        ->maxLength(200)
                        ->columnSpanFull()
                        ->placeholder('Ej. Perfumería importada. Envíos a todo el país.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Catálogo publicado')
                        ->default(true)
                        ->helperText('Si lo apagas, el enlace deja de funcionar sin que pierdas la configuración.'),

                    Forms\Components\Toggle::make('allow_indexing')
                        ->label('Permitir que aparezca en Google')
                        ->helperText('Apagado, el catálogo solo lo ve quien tenga el enlace. '
                            .'Enciéndelo si quieres que te encuentren buscando.'),
                ]),
        ];
    }

    /** @return list<Forms\Components\Component> */
    protected static function pestanaContenido(): array
    {
        return [
            Forms\Components\Section::make()
                ->description('Los productos no se copian: el catálogo los lee en vivo. '
                    .'Un cambio de precio o una foto nueva aparecen de inmediato en el enlace.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category_ids')
                        ->label('Categorías a publicar')
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->columnSpanFull()
                        ->options(fn () => Category::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Vacío = todas. Sirve para publicar solo una parte del inventario.'),

                    Forms\Components\Toggle::make('show_prices')
                        ->label('Mostrar precios')
                        ->default(true)
                        ->helperText('Apágalo si prefieres que el cliente pregunte.'),

                    Forms\Components\Toggle::make('show_codes')
                        ->label('Mostrar el código del producto')
                        ->helperText('Útil si tus clientes piden por referencia.'),

                    Forms\Components\Toggle::make('show_stock')
                        ->label('Mostrar disponibilidad')
                        ->helperText('Muestra «Disponible» o «Agotado». Nunca la cantidad exacta: '
                            .'esa es información de tu negocio.'),

                    Forms\Components\Toggle::make('only_with_image')
                        ->label('Solo productos con foto')
                        ->helperText('Un catálogo con recuadros vacíos se ve peor que uno más corto.'),

                    Forms\Components\Placeholder::make('cuantos')
                        ->label('Productos que se publicarían ahora')
                        ->columnSpanFull()
                        ->content(function (?ProductCatalog $record, Forms\Get $get) {
                            if (! $record) {
                                return 'Guarda el catálogo para ver la cuenta.';
                            }

                            // Se cuenta sobre lo que hay en el formulario, no
                            // sobre lo guardado: así el número responde a lo que
                            // el usuario acaba de marcar.
                            $simulado = clone $record;
                            $simulado->category_ids = $get('category_ids') ?: [];
                            $simulado->only_with_image = (bool) $get('only_with_image');

                            $total = app(CatalogProducts::class)->total($simulado);

                            return $total === 0
                                ? 'Ninguno. Revisa que tus productos estén activos y marcados como vendibles.'
                                : $total.' '.($total === 1 ? 'producto' : 'productos');
                        }),
                ]),
        ];
    }

    /** @return list<Forms\Components\Component> */
    protected static function pestanaDiseno(): array
    {
        return [
            Forms\Components\Section::make('Imágenes')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')
                        ->label('Logo del catálogo')
                        ->image()
                        ->imageEditor()
                        ->directory('catalogs/logos')
                        ->visibility('public')
                        ->maxSize(2048)
                        ->helperText('Si lo dejas vacío se usa el logo de la empresa.'),

                    Forms\Components\FileUpload::make('header_image_path')
                        ->label('Imagen de portada')
                        ->image()
                        ->imageEditor()
                        ->directory('catalogs/headers')
                        ->visibility('public')
                        ->maxSize(4096)
                        ->helperText('Se ve detrás del título. Horizontal y amplia, ej. 1600×600 px. '
                            .'Solo se usa si eliges el estilo de cabecera «Imagen de portada».'),
                ]),

            Forms\Components\Section::make('Colores')
                ->description('Los valores de fábrica son neutros y se ven bien tal cual: cámbialos solo '
                    .'si quieres los de tu marca.')
                ->columns(3)
                ->schema([
                    Forms\Components\ColorPicker::make('theme.primary_color')
                        ->label('Principal')
                        ->default(ProductCatalog::DEFAULT_THEME['primary_color'])
                        ->helperText('Cabecera y precios.'),

                    Forms\Components\ColorPicker::make('theme.accent_color')
                        ->label('Acento')
                        ->default(ProductCatalog::DEFAULT_THEME['accent_color'])
                        ->helperText('Botones y enlaces.'),

                    Forms\Components\ColorPicker::make('theme.bg_color')
                        ->label('Fondo')
                        ->default(ProductCatalog::DEFAULT_THEME['bg_color']),

                    Forms\Components\ColorPicker::make('theme.card_color')
                        ->label('Fondo de las tarjetas')
                        ->default(ProductCatalog::DEFAULT_THEME['card_color']),

                    Forms\Components\ColorPicker::make('theme.text_color')
                        ->label('Texto')
                        ->default(ProductCatalog::DEFAULT_THEME['text_color']),

                    Forms\Components\ColorPicker::make('theme.muted_color')
                        ->label('Texto secundario')
                        ->default(ProductCatalog::DEFAULT_THEME['muted_color'])
                        ->helperText('Descripciones y detalles.'),
                ]),

            Forms\Components\Section::make('Tipografía y disposición')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('theme.font_family')
                        ->label('Fuente')
                        ->options(ProductCatalog::FONT_FAMILIES)
                        ->default(ProductCatalog::DEFAULT_THEME['font_family'])
                        ->native(false)
                        ->helperText('Se carga desde Google Fonts.'),

                    Forms\Components\Select::make('theme.header_style')
                        ->label('Estilo de la cabecera')
                        ->options(ProductCatalog::HEADER_STYLES)
                        ->default(ProductCatalog::DEFAULT_THEME['header_style'])
                        ->native(false),

                    Forms\Components\Select::make('theme.layout')
                        ->label('Disposición')
                        ->options(ProductCatalog::LAYOUTS)
                        ->default(ProductCatalog::DEFAULT_THEME['layout'])
                        ->native(false)
                        ->live(),

                    Forms\Components\Select::make('theme.columns')
                        ->label('Productos por fila')
                        ->options(ProductCatalog::COLUMNS)
                        ->default(ProductCatalog::DEFAULT_THEME['columns'])
                        ->native(false)
                        ->visible(fn (Forms\Get $get) => $get('theme.layout') !== 'list')
                        ->helperText('En celular siempre se ajusta solo.'),

                    Forms\Components\Select::make('theme.card_shape')
                        ->label('Esquinas de las tarjetas')
                        ->options(ProductCatalog::CARD_SHAPES)
                        ->default(ProductCatalog::DEFAULT_THEME['card_shape'])
                        ->native(false),

                    Forms\Components\Toggle::make('theme.show_images')
                        ->label('Mostrar fotos')
                        ->default(true),

                    Forms\Components\Toggle::make('theme.show_descriptions')
                        ->label('Mostrar descripciones')
                        ->default(true),
                ]),
        ];
    }

    /** @return list<Forms\Components\Component> */
    protected static function pestanaContacto(): array
    {
        return [
            Forms\Components\Section::make()
                ->description('Si dejas un campo vacío se usa el de la empresa.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('whatsapp')
                        ->label('WhatsApp para pedidos')
                        ->tel()
                        ->maxLength(30)
                        ->placeholder('3105551234')
                        ->helperText('Pone un botón en cada producto y otro flotante. '
                            .'Si escribes 10 dígitos se asume Colombia; para otro país incluye el indicativo.'),

                    Forms\Components\TextInput::make('contact_phone')
                        ->label('Teléfono visible')
                        ->tel()
                        ->maxLength(30),

                    Forms\Components\TextInput::make('contact_email')
                        ->label('Correo visible')
                        ->email()
                        ->maxLength(150),

                    Forms\Components\Textarea::make('footer_text')
                        ->label('Texto al pie')
                        ->rows(2)
                        ->columnSpanFull()
                        ->placeholder('Ej. Horario: lunes a sábado de 9 a 7. Envíos a todo el país.'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Catálogo')
                    ->weight('semibold')
                    ->description(fn (ProductCatalog $record) => $record->subtitle)
                    ->searchable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Enlace')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (ProductCatalog $record) => $record->publicUrl())
                    ->copyable()
                    ->copyMessage('Enlace copiado')
                    ->url(fn (ProductCatalog $record) => $record->publicUrl(), shouldOpenInNewTab: true)
                    ->color('primary'),

                Tables\Columns\TextColumn::make('productos')
                    ->label('Productos')
                    ->state(fn (ProductCatalog $record) => app(CatalogProducts::class)->total($record))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'warning'),

                Tables\Columns\IconColumn::make('show_prices')
                    ->label('Precios')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Publicado')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Publicado')
                    ->default(true),
            ])
            ->actions([
                Tables\Actions\Action::make('abrir')
                    ->label('Ver')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ProductCatalog $record) => $record->publicUrl(), shouldOpenInNewTab: true),

                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('Todavía no has creado un catálogo')
            ->emptyStateDescription('Un catálogo es un enlace que compartes con tus clientes: '
                .'muestra tus productos con foto y precio, y se actualiza solo cuando los cambias.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductCatalogs::route('/'),
            'create' => Pages\CreateProductCatalog::route('/create'),
            'edit' => Pages\EditProductCatalog::route('/{record}/edit'),
        ];
    }
}
