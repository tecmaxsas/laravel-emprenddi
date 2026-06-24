<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PurchaseInvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Filament\App\Resources\SupportDocumentResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Documento Soporte en adquisiciones. Comparte la tabla y la lógica
 * contable de PurchaseInvoice (kind = support_document): se contabiliza
 * con el mismo PurchaseInvoiceEngine. La transmisión electrónica a DIAN
 * se cablea en una iteración posterior.
 */
class SupportDocumentResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'support_documents.view'; }
    protected static function managePermission(): string { return 'support_documents.create'; }

    protected static ?string $model = PurchaseInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Documentos Soporte';

    protected static ?string $modelLabel = 'Documento Soporte';

    protected static ?string $pluralModelLabel = 'Documentos Soporte';

    protected static ?string $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 15;

    /**
     * Este resource solo opera los documentos soporte; las facturas de
     * compra normales viven en PurchaseInvoiceResource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', PurchaseInvoice::KIND_SUPPORT_DOCUMENT);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->description('El documento soporte lo emite la empresa al comprar a un proveedor no obligado a facturar electrónicamente.')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('DS')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto al guardar')
                        ->columnSpan(3),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Proveedor (no obligado a facturar)')
                        ->required()
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
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
                        ->helperText('Cualquier tercero. Debe tener documento e identificación correctos para la DIAN.')
                        ->columnSpan(2),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Location $l) => [$l->id => $l->fullName()])
                            ->all())
                        ->default(fn () => Location::query()->where('is_main', true)->value('id'))
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha del documento')
                        ->required()
                        ->default(now())
                        ->live(),

                    Forms\Components\TextInput::make('payment_terms_days')
                        ->label('Plazo pago (días)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                            $date = $get('date');
                            if ($date && $state !== null) {
                                $set('due_date', \Carbon\Carbon::parse($date)->addDays((int) $state)->toDateString());
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

            Forms\Components\Section::make('Líneas del documento')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->relationship('lines')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Producto / servicio')
                                ->searchable()
                                ->live()
                                ->getSearchResultsUsing(fn (string $search) => Product::query()
                                    ->where('active', true)
                                    ->where('is_purchasable', true)
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
                                    $set('unit_cost', (float) $product->default_purchase_price);
                                    $set('tax_id', $product->effectivePurchaseTaxId());
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

                            Forms\Components\TextInput::make('unit_cost')
                                ->label('Costo unit.')
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
                                    ->where('is_active', true)
                                    ->whereIn('applies_to', ['purchase', 'both'])
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
                        ->reorderableWithButtons()
                        ->orderColumn('line_number'),
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

            Forms\Components\Textarea::make('notes')
                ->label('Notas / concepto')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Recalcula los campos derivados de una línea (subtotal, descuento,
     * tax_amount, total) — misma fórmula que las facturas de compra.
     */
    protected static function recomputeLine(Forms\Set $set, Forms\Get $get): void
    {
        $qty = (float) ($get('quantity') ?? 0);
        $unitCost = (float) ($get('unit_cost') ?? 0);
        $discountPct = (float) ($get('discount_percentage') ?? 0);

        $subtotal = round($qty * $unitCost, 2);
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
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (PurchaseInvoice $record) => $record->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('number', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Proveedor')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('paid_amount')->label('Pagado')->money('COP')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->state(fn (PurchaseInvoice $record) => $record->balance)
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Pago')
                    ->formatStateUsing(fn (string $state) => PurchaseInvoice::PAYMENT_STATUSES[$state] ?? $state)
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
                    ->formatStateUsing(fn (string $state) => PurchaseInvoice::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(PurchaseInvoice::STATUSES),
                Tables\Filters\SelectFilter::make('payment_status')->label('Pago')->options(PurchaseInvoice::PAYMENT_STATUSES),
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
                Tables\Actions\EditAction::make()->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'),
            ])
            ->emptyStateHeading('Sin documentos soporte')
            ->emptyStateDescription('Registrá las compras a proveedores no obligados a facturar electrónicamente.');
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportDocuments::route('/'),
            'create' => Pages\CreateSupportDocument::route('/create'),
            'view' => Pages\ViewSupportDocument::route('/{record}'),
            'edit' => Pages\EditSupportDocument::route('/{record}/edit'),
        ];
    }
}
