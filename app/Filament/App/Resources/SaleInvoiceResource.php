<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SaleInvoiceResource\Pages;
use App\Filament\App\Resources\SaleInvoiceResource\RelationManagers;
use App\Filament\Concerns\ChecksPermission;
use App\Filament\Concerns\PreviewsGlobalDiscount;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Support\DianDvCalculator;
use App\Support\RetentionBase;
use App\Support\StockPreview;
use App\Models\User;
use App\Support\Dian\DianInvoiceActions;
use App\Support\ProductOptions;
use App\Support\TaxOptions;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class SaleInvoiceResource extends Resource
{
    use ChecksPermission, PreviewsGlobalDiscount;

    protected static function viewPermission(): string
    {
        return 'sales.view';
    }

    protected static function managePermission(): string
    {
        return 'sales.create';
    }

    protected static ?string $model = SaleInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Facturas de Venta';

    protected static ?string $modelLabel = 'Factura de Venta';

    protected static ?string $pluralModelLabel = 'Facturas de Venta';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 20;

    /**
     * Las resoluciones que se pueden elegir para numerar.
     *
     * Se listan TODAS las activas del tipo pedido, no solo las asignadas a la
     * sede: ese es el punto de este selector. La etiqueta dice lo que hay que
     * saber para escoger —rango, cuánto queda y si está vencida— porque emitir
     * con una resolución vencida es un rechazo seguro de la DIAN y el sistema no
     * lo impide: a veces la fecha en el sistema está desactualizada y bloquear
     * la facturación sería peor.
     *
     * @return array<int, string>
     */
    public static function resolutionOptions(?string $kind): array
    {
        $kind = $kind === 'pos' ? Resolution::KIND_POS : Resolution::KIND_ELECTRONIC;

        $sedes = LocationResolution::query()
            ->where('active', true)
            ->pluck('dian_resolution_id')
            ->unique()
            ->all();

        return Resolution::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('kind', $kind)
            ->where('document_type_id', 1)
            ->where('active', true)
            ->orderBy('prefix')
            ->get()
            ->mapWithKeys(function (Resolution $r) use ($sedes) {
                $partes = [$r->prefix];

                if ($r->resolution_number) {
                    $partes[] = 'Res. '.$r->resolution_number;
                }

                $partes[] = number_format((float) $r->range_from, 0, ',', '.')
                    .'–'.number_format((float) $r->range_to, 0, ',', '.');

                if ($r->date_to && $r->date_to->isPast()) {
                    $partes[] = '⚠ VENCIDA el '.$r->date_to->format('d/m/Y');
                } elseif ($r->date_to) {
                    $partes[] = 'vence '.$r->date_to->format('d/m/Y');
                }

                if (! in_array($r->id, $sedes, true)) {
                    $partes[] = 'sin asignar a ninguna sede';
                }

                return [$r->id => implode(' · ', $partes)];
            })
            ->all();
    }

    protected static function allowsGlobalDiscount(): bool
    {
        return static::discountsEnabledFor('sales');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('invoice_kind')
                        ->label('Tipo de factura')
                        ->options([
                            'electronic' => 'Electrónica (DIAN)',
                            'pos' => 'POS (sin DIAN)',
                        ])
                        ->default('electronic')
                        ->required()
                        ->native(false)
                        ->disabledOn('edit')
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('dian_resolution_id', null))
                        ->helperText('Define de qué resolución sale el consecutivo.'),

                    // La resolucion se puede elegir a mano, este o no asignada a
                    // la sede. La asignacion es una comodidad, no una regla del
                    // negocio: una empresa con varias resoluciones vigentes
                    // necesita poder emitir con una concreta —la del contrato de
                    // un cliente, la que esta por vencerse y hay que agotar— sin
                    // reasignarla y volver a dejarla como estaba.
                    Forms\Components\Select::make('dian_resolution_id')
                        ->label('Resolución')
                        ->placeholder('La que tenga asignada la sede')
                        ->options(fn (Forms\Get $get) => self::resolutionOptions($get('invoice_kind')))
                        ->native(false)
                        ->searchable()
                        ->disabledOn('edit')
                        ->helperText('Déjalo vacío para usar la resolución asignada a la sede. '
                            .'Elige una para numerar con esa, esté asignada o no.'),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->extraInputAttributes(['onwheel' => 'this.blur()'])
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('Auto: de la resolución'),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->required()
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('document_number', 'ilike', "%{$search}%")
                                    ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => "{$t->document_number} — {$t->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ThirdParty::find($value)
                            ? ThirdParty::find($value)->document_number.' — '.ThirdParty::find($value)->name
                            : null)
                        // Crearlo aqui mismo. Lo contrario es perder la factura
                        // a medio armar para ir a registrar al cliente, y
                        // volver a empezar.
                        ->createOptionForm([
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\Select::make('document_type')
                                    ->label('Tipo de documento')
                                    ->options(ThirdParty::DOCUMENT_TYPES)
                                    ->default('cc')
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\TextInput::make('document_number')
                                    ->label('Numero de documento')
                                    ->required()
                                    ->maxLength(30)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        if ($get('document_type') === 'nit' && $state) {
                                            $set('dv', DianDvCalculator::calculate((string) $state));
                                        }
                                    }),
                            ]),

                            Forms\Components\TextInput::make('name')
                                ->label('Nombre o razon social')
                                ->required()
                                ->maxLength(200),

                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('phone')
                                    ->label('Telefono')
                                    ->maxLength(30),

                                Forms\Components\TextInput::make('email')
                                    ->label('Correo')
                                    ->email()
                                    ->maxLength(150),
                            ]),

                            Forms\Components\TextInput::make('address')
                                ->label('Direccion')
                                ->maxLength(255),

                            Forms\Components\Hidden::make('dv'),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            $companyId = (int) auth()->user()->company_id;

                            // Si ya existe ese documento no se crea otro: el
                            // indice unico lo impediria, pero un error crudo de
                            // la base no le dice nada a quien esta facturando.
                            $existente = ThirdParty::query()
                                ->where('company_id', $companyId)
                                ->where('document_type', $data['document_type'])
                                ->where('document_number', $data['document_number'])
                                ->first();

                            if ($existente) {
                                if (! $existente->is_customer) {
                                    $existente->update(['is_customer' => true]);
                                }

                                Notification::make()
                                    ->warning()
                                    ->title('Ese documento ya existia')
                                    ->body("Se uso el tercero ya registrado: {$existente->name}.")
                                    ->send();

                                return $existente->id;
                            }

                            return ThirdParty::create([
                                ...$data,
                                'company_id' => $companyId,
                                'person_type' => $data['document_type'] === 'nit' ? 'juridica' : 'natural',
                                'is_customer' => true,
                                'active' => true,
                            ])->id;
                        })
                        ->createOptionModalHeading('Nuevo cliente')
                        ->columnSpan(2),

                    Forms\Components\Select::make('seller_user_id')
                        ->label('Vendedor')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where('company_id', auth()->user()->company_id)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                    ->orWhere('email', 'ilike', "%{$search}%");
                            })
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (User $u) => [$u->id => $u->name ?: $u->email])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => User::find($value)?->name ?: User::find($value)?->email)
                        ->default(fn () => Auth::id())
                        ->columnSpan(2),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede que vende')
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Location $l) => [$l->id => $l->fullName()])
                            ->all())
                        ->default(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_main', true)
                            ->value('id'))
                        ->native(false)
                        // Reactiva porque el stock que se muestra en cada linea
                        // depende de la sede que vende.
                        ->live()
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha factura')
                        ->required()
                        ->default(now())
                        ->live(),

                    Forms\Components\TextInput::make('payment_terms_days')
                        ->label('Plazo pago (días)')
                        ->numeric()
                        ->extraInputAttributes(['onwheel' => 'this.blur()'])
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                            $date = $get('date');
                            if ($date && $state !== null) {
                                $set('due_date', Carbon::parse($date)->addDays((int) $state)->toDateString());
                            }
                        }),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Fecha vencimiento'),

                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD'])
                        ->default('COP')
                        ->required(),
                ]),

            Forms\Components\Section::make('Líneas de la factura')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->relationship('lines')
                        ->orderColumn('line_number')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Producto')
                                ->searchable()
                                ->live()
                                // Precargado: al abrirlo ya hay productos, no
                                // un cajon vacio que exige saber el nombre.
                                ->options(fn () => ProductOptions::initial('sale'))
                                ->getSearchResultsUsing(fn (string $search) => ProductOptions::search('sale', $search))
                                ->getOptionLabelUsing(fn ($value) => ProductOptions::label($value))
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if (! $state) {
                                        return;
                                    }
                                    $product = Product::find($state);
                                    if (! $product) {
                                        return;
                                    }
                                    $set('description', $product->name);
                                    $set('unit_price', (float) $product->default_sale_price);
                                    $set('cost_at_sale', (float) ($product->default_purchase_price ?? 0));
                                    $set('tax_id', $product->default_sale_tax_id);
                                })
                                // Crearlo desde aqui. Un producto que no existe
                                // no puede obligar a abandonar la factura a
                                // medio armar para ir a registrarlo.
                                ->createOptionForm([
                                    Forms\Components\Grid::make(2)->schema([
                                        Forms\Components\TextInput::make('code')
                                            ->label('Codigo / SKU')
                                            ->required()
                                            ->maxLength(60),

                                        Forms\Components\Select::make('type')
                                            ->label('Tipo')
                                            ->options(Product::TYPES)
                                            ->default('good')
                                            ->required()
                                            ->native(false)
                                            ->live(),
                                    ]),

                                    Forms\Components\TextInput::make('name')
                                        ->label('Nombre')
                                        ->required()
                                        ->maxLength(200),

                                    Forms\Components\Grid::make(3)->schema([
                                        Forms\Components\Select::make('unit_of_measure')
                                            ->label('Unidad')
                                            ->options(Product::COMMON_UNITS)
                                            ->default('unit')
                                            ->required()
                                            ->native(false),

                                        Forms\Components\TextInput::make('default_sale_price')
                                            ->label('Precio de venta')
                                            ->numeric()
                                            ->minValue(0)
                                            ->prefix('$')
                                            ->default(0)
                                            ->required()
                                            ->extraInputAttributes(['onwheel' => 'this.blur()']),

                                        Forms\Components\TextInput::make('default_purchase_price')
                                            ->label('Costo')
                                            ->numeric()
                                            ->minValue(0)
                                            ->prefix('$')
                                            ->default(0)
                                            ->extraInputAttributes(['onwheel' => 'this.blur()']),
                                    ]),

                                    Forms\Components\Select::make('default_sale_tax_id')
                                        ->label('Impuesto de venta')
                                        ->options(fn () => TaxOptions::taxes('sale'))
                                        ->native(false)
                                        ->placeholder('Sin impuesto'),

                                    Forms\Components\Toggle::make('track_inventory')
                                        ->label('Controla inventario')
                                        ->helperText('Un servicio no lo controla. Un bien creado aqui '
                                            .'arranca en cero: la entrada se registra por separado.')
                                        ->default(fn (Forms\Get $get) => $get('type') !== 'service'),
                                ])
                                ->createOptionUsing(function (array $data): int {
                                    $companyId = (int) auth()->user()->company_id;

                                    $existente = Product::query()
                                        ->where('company_id', $companyId)
                                        ->where('code', $data['code'])
                                        ->first();

                                    if ($existente) {
                                        Notification::make()
                                            ->warning()
                                            ->title('Ese codigo ya existia')
                                            ->body("Se uso el producto ya registrado: {$existente->name}.")
                                            ->send();

                                        return $existente->id;
                                    }

                                    return Product::create([
                                        ...$data,
                                        'company_id' => $companyId,
                                        'is_sellable' => true,
                                        'active' => true,
                                    ])->id;
                                })
                                ->createOptionModalHeading('Nuevo producto')
                                ->columnSpan(['default' => 1, 'md' => 6, 'xl' => 5]),

                            Forms\Components\TextInput::make('description')
                                ->label('Descripción')
                                ->required()
                                ->maxLength(250)
                                ->columnSpan(['default' => 1, 'md' => 6, 'xl' => 7]),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cant.')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->minValue(0)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2]),

                            Forms\Components\TextInput::make('unit_price')
                                ->label('Precio unit.')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->minValue(0)
                                ->prefix('$')
                                ->default(0)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 3]),

                            Forms\Components\TextInput::make('discount_percentage')
                                ->label('Desc. %')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->minValue(0)
                                ->maxValue(100)
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2]),

                            Forms\Components\Select::make('tax_id')
                                ->label('Impuesto')
                                ->live()
                                ->searchable()
                                // Son pocos por empresa: se cargan todos.
                                ->options(fn () => TaxOptions::taxes('sale'))
                                ->getOptionLabelUsing(fn ($value) => ($t = Tax::find($value))
                                    ? TaxOptions::shortLabel($t)
                                    : null)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 3, 'xl' => 3]),

                            Forms\Components\TextInput::make('total')
                                ->label('Total')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->prefix('$')
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->columnSpan(['default' => 1, 'md' => 3, 'xl' => 2]),

                            // Cuanto hay y cuanto va a quedar, mientras se
                            // digita. Sin esto hay que irse a inventario y
                            // volver, y la venta imposible se descubre con el
                            // cliente esperando.
                            Forms\Components\Placeholder::make('stock_preview')
                                ->label('')
                                ->content(fn (Forms\Get $get) => StockPreview::paraLinea(
                                    $get('product_id') ? (int) $get('product_id') : null,
                                    $get('../../location_id') ? (int) $get('../../location_id') : null,
                                    (float) ($get('quantity') ?? 0),
                                ))
                                ->visible(fn (Forms\Get $get) => (bool) $get('product_id'))
                                ->columnSpanFull(),

                            Forms\Components\Hidden::make('subtotal')->default(0),
                            Forms\Components\Hidden::make('discount_amount')->default(0),
                            Forms\Components\Hidden::make('tax_rate')->default(0),
                            Forms\Components\Hidden::make('tax_amount')->default(0),
                            Forms\Components\Hidden::make('cost_at_sale')->default(0),
                        ])
                        ->columns(['default' => 1, 'md' => 6, 'xl' => 12])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live()
                        ->addActionLabel('+ Añadir línea')
                        ->reorderableWithButtons(),
                ]),

            Forms\Components\Section::make('Retenciones')
                ->description('Solo si el cliente es agente retenedor (Gran Contribuyente, Estado, etc.). Vacío para clientes finales.')
                ->collapsed(fn (?SaleInvoice $record) => ! $record || $record->retentions()->doesntExist())
                ->schema([
                    Forms\Components\Repeater::make('retentions')
                        ->relationship('retentions')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('tax_id')
                                ->label('Tipo de retención')
                                ->required()
                                ->live()
                                ->searchable()
                                ->options(fn () => TaxOptions::retentions('sale'))
                                ->getOptionLabelUsing(fn ($value) => ($t = Tax::find($value))
                                    ? TaxOptions::longLabel($t)
                                    : null)
                                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                    if (! $state) {
                                        return;
                                    }
                                    $tax = Tax::find($state);
                                    if (! $tax) {
                                        return;
                                    }
                                    $set('tax_code', $tax->code);
                                    $set('tax_name', $tax->name);
                                    $set('tax_type', $tax->type);
                                    $set('rate', (float) $tax->rate);

                                    // La base depende del tipo: ReteIVA va
                                    // sobre el IVA, las demas sobre el
                                    // subtotal. Se sugiere sola para que el
                                    // error mas caro no dependa de recordarlo.
                                    if ((float) ($get('base_amount') ?? 0) <= 0) {
                                        $set('base_amount', self::retentionBaseFor(
                                            $tax->type,
                                            $get('../../lines'),
                                        ));
                                    }

                                    self::recomputeRetention($set, $get);
                                })
                                ->columnSpan(['default' => 1, 'md' => 6, 'xl' => 5]),

                            Forms\Components\TextInput::make('base_amount')
                                ->label('Base (gravable)')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->minValue(0)
                                ->prefix('$')
                                ->default(0)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeRetention($set, $get))
                                ->helperText('Se sugiere sola: subtotal menos descuentos, o el IVA si es ReteIVA. Ajústala si la base gravable es otra.')
                                ->columnSpan(['default' => 1, 'md' => 3, 'xl' => 4]),

                            Forms\Components\TextInput::make('rate')
                                ->label('%')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->minValue(0)
                                ->maxValue(100)
                                ->step(0.0001)
                                ->suffix('%')
                                ->disabled()
                                ->dehydrated()
                                ->columnSpan(['default' => 1, 'md' => 3, 'xl' => 2]),

                            Forms\Components\TextInput::make('amount')
                                ->label('Monto retenido')
                                ->numeric()
                                ->extraInputAttributes(['onwheel' => 'this.blur()'])
                                ->prefix('$')
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->columnSpan(['default' => 1, 'md' => 6, 'xl' => 3]),

                            Forms\Components\Hidden::make('tax_code')->default(''),
                            Forms\Components\Hidden::make('tax_name')->default(''),
                            Forms\Components\Hidden::make('tax_type')->default(''),
                        ])
                        ->columns(['default' => 1, 'md' => 6, 'xl' => 14])
                        ->defaultItems(0)
                        ->live()
                        ->addActionLabel('+ Añadir retención')
                        ->reorderableWithButtons(),
                ]),

            ...self::globalDiscountSection(),

            Forms\Components\Section::make('Totales')
                ->columns(6)
                ->schema([
                    Forms\Components\Placeholder::make('subtotal_display')
                        ->label('Subtotal')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['subtotal'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('discount_display')
                        ->label('Descuento')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            self::previewTotals($get)['discount'], 2)),
                    Forms\Components\Placeholder::make('tax_display')
                        ->label('IVA')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            self::previewTotals($get)['tax'], 2)),
                    Forms\Components\Placeholder::make('total_display')
                        ->label('Total')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            self::previewTotals($get)['total'], 2)),
                    Forms\Components\Placeholder::make('retention_display')
                        ->label('Retenciones')
                        ->content(fn (Forms\Get $get) => '− $ '.number_format(
                            collect($get('retentions') ?? [])->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('net_payable_display')
                        ->label('NETO A PAGAR')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            self::previewTotals($get)['total']
                            - collect($get('retentions') ?? [])->sum(fn ($r) => (float) ($r['amount'] ?? 0)),
                            2,
                        )),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas internas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Base sugerida para una retencion, segun su tipo.
     *
     * La regla vive en RetentionBase porque toma de pedidos la necesita igual, y
     * ya se habia escrito dos veces: alli se aplicaba la misma base a todas las
     * retenciones, asi que una ReteIVA salia multiplicada por varias veces su
     * valor.
     *
     * Es una sugerencia, no una imposicion: el usuario puede ajustarla cuando la
     * base gravable no sea toda la factura.
     *
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    protected static function retentionBaseFor(?string $taxType, ?array $lines): float
    {
        return RetentionBase::deLineas($taxType, $lines);
    }

    /** Recalcula el monto retenido = base × rate / 100. */
    protected static function recomputeRetention(Forms\Set $set, Forms\Get $get): void
    {
        $base = (float) ($get('base_amount') ?? 0);
        $rate = (float) ($get('rate') ?? 0);
        $set('amount', round($base * ($rate / 100), 2));
    }

    /**
     * Recalcula campos derivados (subtotal, descuento, tax_amount, total)
     * sobre la base de quantity, unit_price, discount_percentage, tax_id.
     */
    protected static function recomputeLine(Forms\Set $set, Forms\Get $get): void
    {
        $qty = (float) ($get('quantity') ?? 0);
        $unitPrice = (float) ($get('unit_price') ?? 0);
        $discountPct = (float) ($get('discount_percentage') ?? 0);

        $subtotal = round($qty * $unitPrice, 2);
        $discountAmount = round($subtotal * ($discountPct / 100), 2);
        $taxable = $subtotal - $discountAmount;

        $taxRate = 0;
        $taxAmount = 0;
        if ($taxId = $get('tax_id')) {
            $tax = Tax::find($taxId);
            if ($tax) {
                $taxRate = (float) $tax->rate;
                $taxAmount = round($taxable * ($taxRate / 100), 2);
            }
        }

        $set('subtotal', $subtotal);
        $set('discount_amount', $discountAmount);
        $set('tax_rate', $taxRate);
        $set('tax_amount', $taxAmount);
        $set('total', $taxable + $taxAmount);
    }

    public static function table(Table $table): Table
    {
        return $table
            // `date` es una fecha sin hora: sin desempate, dentro del mismo dia
            // PostgreSQL devuelve las filas en el orden que quiera, y el listado
            // sale con los consecutivos revueltos. El `id` cierra el empate
            // cuando dos documentos comparten fecha y numero, que pasa con
            // prefijos distintos.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByDesc('date')
                ->orderByDesc('number')
                ->orderByDesc('id'))
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (SaleInvoice $record) => $record->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('number', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),

                Tables\Columns\TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('retention_total')
                    ->label('Retención')
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('net_payable')
                    ->label('Neto')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('paid_amount')->label('Pagado')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->state(fn (SaleInvoice $record) => $record->balance)
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Pago')
                    ->formatStateUsing(fn (string $state) => SaleInvoice::PAYMENT_STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pendiente' => 'warning',
                        'parcial' => 'info',
                        'pagado' => 'success',
                        'vencido' => 'danger',
                        'cancelada' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => SaleInvoice::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                    }),

                Tables\Columns\TextColumn::make('invoice_kind')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => $state === 'pos' ? 'POS' : 'Electrónica')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'pos' ? 'gray' : 'info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('dian_status')
                    ->label('DIAN')
                    ->formatStateUsing(fn (?string $state) => $state ? (SaleInvoice::DIAN_STATUSES[$state] ?? $state) : '—')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'accepted' => 'success',
                        'sent' => 'info',
                        'pending' => 'gray',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(SaleInvoice::STATUSES),
                Tables\Filters\SelectFilter::make('payment_status')->label('Pago')->options(SaleInvoice::PAYMENT_STATUSES),
                Tables\Filters\SelectFilter::make('invoice_kind')
                    ->label('Tipo de factura')
                    ->options([
                        'electronic' => 'Electrónica (DIAN)',
                        'pos' => 'POS (no va a DIAN)',
                    ])
                    // Las facturas viejas quedaron con invoice_kind null y son
                    // electronicas, igual que las trata SaleInvoice::isPosInvoice().
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $kind) => $kind === 'pos'
                            ? $q->where('invoice_kind', 'pos')
                            : $q->where(fn (Builder $sub) => $sub->where('invoice_kind', 'electronic')->orWhereNull('invoice_kind')),
                    )),

                Tables\Filters\SelectFilter::make('dian_status')->label('DIAN')->options(SaleInvoice::DIAN_STATUSES),
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Sede')
                    ->relationship('location', 'name'),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Desde'),
                        Forms\Components\DatePicker::make('to')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (SaleInvoice $record) => $record->status === 'draft'),
                Tables\Actions\Action::make('print')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (SaleInvoice $record) => route('pos.print', ['invoice' => $record->id]))
                    ->openUrlInNewTab(),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('checkDianStatus')
                        ->label('Consultar estado DIAN')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(fn (SaleInvoice $record) => DianInvoiceActions::checkStatus($record)),

                    Tables\Actions\Action::make('resendDianEmail')
                        ->label('Reenviar por correo')
                        ->icon('heroicon-o-envelope')
                        ->color('gray')
                        ->modalHeading(fn (SaleInvoice $record) => 'Reenviar factura '.$record->fullNumber())
                        ->modalSubmitActionLabel('Reenviar')
                        ->fillForm(fn (SaleInvoice $record) => DianInvoiceActions::resendEmailDefaults($record))
                        ->form(DianInvoiceActions::resendEmailForm())
                        ->action(fn (SaleInvoice $record, array $data) => DianInvoiceActions::resendEmail($record, $data)),
                ])
                    ->label('DIAN')
                    ->icon('heroicon-o-document-check')
                    ->visible(fn (SaleInvoice $record) => DianInvoiceActions::isManageable($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('checkDianStatusBulk')
                    ->label('Consultar estado en DIAN')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->deselectRecordsAfterCompletion()
                    ->modalHeading('Consultar estado en DIAN')
                    ->modalDescription('Se consulta una por una contra la DIAN y se actualiza el estado guardado. Las facturas POS y las que aún no tienen CUFE se omiten.')
                    ->modalSubmitActionLabel('Consultar')
                    ->action(function (Collection $records) {
                        $accepted = $rejected = $pending = $skipped = $failed = 0;

                        foreach ($records as $record) {
                            if (! DianInvoiceActions::isManageable($record)) {
                                $skipped++;

                                continue;
                            }

                            $result = DianInvoiceActions::checkStatus($record, notify: false);

                            if (! $result['ok']) {
                                $failed++;

                                continue;
                            }

                            match ($result['status']) {
                                SaleInvoice::DIAN_ACCEPTED => $accepted++,
                                SaleInvoice::DIAN_REJECTED => $rejected++,
                                default => $pending++,
                            };
                        }

                        $parts = [];
                        if ($accepted) {
                            $parts[] = "{$accepted} autorizada(s)";
                        }
                        if ($rejected) {
                            $parts[] = "{$rejected} rechazada(s)";
                        }
                        if ($pending) {
                            $parts[] = "{$pending} en validación";
                        }
                        if ($skipped) {
                            $parts[] = "{$skipped} omitida(s) (POS o sin CUFE)";
                        }
                        if ($failed) {
                            $parts[] = "{$failed} sin respuesta";
                        }

                        Notification::make()
                            ->title('Consulta finalizada')
                            ->body($parts ? implode(' · ', $parts) : 'No había facturas que consultar.')
                            ->status($rejected > 0 || $failed > 0 ? 'warning' : 'success')
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSaleInvoices::route('/'),
            'create' => Pages\CreateSaleInvoice::route('/create'),
            'view' => Pages\ViewSaleInvoice::route('/{record}'),
            'edit' => Pages\EditSaleInvoice::route('/{record}/edit'),
        ];
    }
}
