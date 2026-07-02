<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\QuotationResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Location;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class QuotationResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'quotations.view'; }
    protected static function managePermission(): string { return 'quotations.manage'; }

    protected static ?string $model = Quotation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Cotizaciones';

    protected static ?string $modelLabel = 'Cotización';

    protected static ?string $pluralModelLabel = 'Cotizaciones';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('COT')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto al guardar'),

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
                        ->label('Sede')
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
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha')
                        ->required()
                        ->default(now()),

                    Forms\Components\DatePicker::make('valid_until')
                        ->label('Válida hasta')
                        ->default(now()->addDays(30))
                        ->helperText('Fecha de expiración de la propuesta.'),

                    Forms\Components\Select::make('currency')
                        ->label('Moneda')
                        ->options(['COP' => 'COP', 'USD' => 'USD'])
                        ->default('COP')
                        ->required()
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('Líneas')
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
                                ->getSearchResultsUsing(fn (string $search) => Product::query()
                                    ->where('company_id', auth()->user()?->company_id)
                                    ->where('active', true)
                                    ->where('is_sellable', true)
                                    ->where('type', '!=', 'variable')
                                    ->where(function ($q) use ($search) {
                                        $q->where('code', 'ilike', "%{$search}%")
                                          ->orWhere('name', 'ilike', "%{$search}%")
                                          ->orWhere('barcode', 'ilike', "%{$search}%");
                                    })
                                    ->orderBy('name')
                                    ->limit(30)
                                    ->get()
                                    ->mapWithKeys(fn (Product $p) => [$p->id => "{$p->code} — {$p->name}"])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => Product::find($value)
                                    ? Product::find($value)->code.' — '.Product::find($value)->name
                                    : null)
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if (! $state) return;
                                    $product = Product::find($state);
                                    if (! $product) return;
                                    $set('description', $product->name);
                                    $set('unit_price', (float) $product->default_sale_price);
                                    $set('tax_id', $product->default_sale_tax_id);
                                })
                                ->columnSpan(4),

                            Forms\Components\TextInput::make('description')
                                ->label('Descripción')
                                ->required()
                                ->maxLength(250)
                                ->columnSpan(4),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cant.')
                                ->numeric()
                                ->minValue(0)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(1),

                            Forms\Components\TextInput::make('unit_price')
                                ->label('Precio unit.')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->default(0)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('discount_percentage')
                                ->label('Desc. %')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(1),

                            Forms\Components\Select::make('tax_id')
                                ->label('Impuesto')
                                ->live()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Tax::query()
                                    ->where('company_id', auth()->user()?->company_id)
                                    ->where('is_active', true)
                                    ->whereIn('applies_to', ['sale', 'both'])
                                    ->where(function ($q) use ($search) {
                                        $q->where('code', 'ilike', "%{$search}%")
                                          ->orWhere('name', 'ilike', "%{$search}%");
                                    })
                                    ->limit(20)
                                    ->get()
                                    ->mapWithKeys(fn (Tax $t) => [$t->id => "{$t->code} ({$t->rate}%)"])
                                    ->all())
                                ->getOptionLabelUsing(fn ($value) => Tax::find($value)
                                    ? Tax::find($value)->code.' ('.Tax::find($value)->rate.'%)'
                                    : null)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('total')
                                ->label('Total')
                                ->numeric()
                                ->prefix('$')
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->columnSpan(2),

                            Forms\Components\Hidden::make('subtotal')->default(0),
                            Forms\Components\Hidden::make('discount_amount')->default(0),
                            Forms\Components\Hidden::make('tax_rate')->default(0),
                            Forms\Components\Hidden::make('tax_amount')->default(0),
                        ])
                        ->columns(16)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live()
                        ->addActionLabel('+ Añadir línea')
                        ->reorderableWithButtons(),
                ]),

            Forms\Components\Section::make('Totales')
                ->columns(4)
                ->schema([
                    Forms\Components\Placeholder::make('subtotal_display')
                        ->label('Subtotal')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['subtotal'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('discount_display')
                        ->label('Descuento')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['discount_amount'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('tax_display')
                        ->label('IVA')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['tax_amount'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('total_display')
                        ->label('TOTAL')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['total'] ?? 0)), 2)),
                ]),

            Forms\Components\Textarea::make('terms_and_conditions')
                ->label('Términos y condiciones')
                ->placeholder('Validez 30 días. Precios en COP, sujetos a IVA cuando aplique. Forma de pago: ...')
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('notes')
                ->label('Notas internas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

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
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                // Auto-marca expired las que pasaron de fecha sin aprobación
                $query->where(function ($q) {
                    $q->whereNotIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])
                      ->orWhereNull('valid_until')
                      ->orWhere('valid_until', '>=', now()->toDateString());
                });
                return $query;
            })
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (Quotation $record) => $record->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('number', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Vence')
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->color(fn (Quotation $r) => $r->isEffectivelyExpired() ? 'danger' : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('seller.name')->label('Vendedor')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (Quotation $r) => $r->isEffectivelyExpired()
                        ? Quotation::STATUSES[Quotation::STATUS_EXPIRED]
                        : (Quotation::STATUSES[$r->status] ?? $r->status))
                    ->badge()
                    ->color(fn (Quotation $r) => match (true) {
                        $r->isEffectivelyExpired() => 'danger',
                        $r->status === Quotation::STATUS_DRAFT => 'gray',
                        $r->status === Quotation::STATUS_SENT => 'info',
                        $r->status === Quotation::STATUS_APPROVED => 'success',
                        $r->status === Quotation::STATUS_REJECTED => 'danger',
                        $r->status === Quotation::STATUS_CONVERTED => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('convertedTo.full_number')
                    ->label('Factura')
                    ->state(fn (Quotation $r) => $r->convertedTo?->fullNumber())
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(Quotation::STATUSES),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Desde'),
                        Forms\Components\DatePicker::make('to')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Quotation $r) => $r->isDraft()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Quotation $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
            'view' => Pages\ViewQuotation::route('/{record}'),
            'edit' => Pages\EditQuotation::route('/{record}/edit'),
        ];
    }
}
