<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PurchaseReturnResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Services\Inventory\InventoryEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseReturnResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'purchases.view'; }
    protected static function managePermission(): string { return 'purchases.create'; }

    protected static ?string $model = PurchaseReturn::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Devoluciones';

    protected static ?string $modelLabel = 'Devolución a proveedor';

    protected static ?string $pluralModelLabel = 'Devoluciones a proveedor';

    protected static ?string $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('DEV')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto al guardar'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha')
                        ->required()
                        ->default(now())
                        ->columnSpan(2),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Proveedor')
                        ->required()
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('is_supplier', true)
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

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->live()
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => Location::query()->where('is_main', true)->value('id'))
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\Select::make('purchase_invoice_id')
                        ->label('Factura origen (opcional)')
                        ->placeholder('Sin referencia a factura')
                        ->searchable()
                        ->helperText('Útil para trazabilidad. La devolución NO modifica el saldo de la factura origen.')
                        ->getSearchResultsUsing(function (string $search, Forms\Get $get) {
                            $supplierId = $get('third_party_id');
                            if (! $supplierId) return [];
                            return PurchaseInvoice::query()
                                ->where('third_party_id', $supplierId)
                                ->where('status', 'posted')
                                ->where(function ($q) use ($search) {
                                    $q->where('number', 'like', "%{$search}%")
                                      ->orWhere('supplier_invoice_number', 'ilike', "%{$search}%");
                                })
                                ->orderByDesc('date')
                                ->limit(30)
                                ->get()
                                ->mapWithKeys(fn (PurchaseInvoice $p) => [$p->id => $p->fullNumber().' ('.$p->date->format('Y-m-d').' — $'.number_format($p->total, 0).')'])
                                ->all();
                        })
                        ->getOptionLabelUsing(fn ($value) => PurchaseInvoice::find($value)?->fullNumber())
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('reason')
                        ->label('Motivo de devolución')
                        ->placeholder('Ej. Producto defectuoso, exceso recibido, etc.')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Productos a devolver')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->relationship('lines')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Producto')
                                ->required()
                                ->searchable()
                                ->live()
                                ->getSearchResultsUsing(fn (string $search) => Product::query()
                                    ->where('track_inventory', true)
                                    ->where('active', true)
                                    ->where(function ($q) use ($search) {
                                        $q->where('code', 'ilike', "%{$search}%")
                                          ->orWhere('name', 'ilike', "%{$search}%");
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
                                    $set('tax_id', $product->default_purchase_tax_id);
                                })
                                ->columnSpan(5),

                            Forms\Components\TextInput::make('description')
                                ->label('Descripción')
                                ->required()
                                ->maxLength(250)
                                ->columnSpan(7),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cant.')
                                ->numeric()
                                ->minValue(0.0001)
                                ->required()
                                ->default(1)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('unit_cost')
                                ->label('Costo unit.')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->prefix('$')
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(2),

                            Forms\Components\Select::make('tax_id')
                                ->label('IVA')
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
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('total')
                                ->label('Total')
                                ->numeric()
                                ->prefix('$')
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->columnSpan(3),

                            Forms\Components\Placeholder::make('stock_disponible')
                                ->label('Stock disponible')
                                ->content(function (Forms\Get $get) {
                                    $productId = $get('product_id');
                                    $locId = $get('../../location_id');
                                    if (! $productId || ! $locId) return '—';
                                    return number_format(app(InventoryEngine::class)->currentStock((int) $productId, (int) $locId), 2);
                                })
                                ->columnSpan(2),

                            Forms\Components\Hidden::make('subtotal')->default(0),
                            Forms\Components\Hidden::make('tax_rate')->default(0),
                            Forms\Components\Hidden::make('tax_amount')->default(0),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live()
                        ->addActionLabel('+ Añadir línea')
                        ->reorderableWithButtons(),
                ]),

            Forms\Components\Section::make('Totales')
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('subtotal_display')
                        ->label('Subtotal')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['subtotal'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('tax_display')
                        ->label('IVA')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['tax_amount'] ?? 0)), 2)),
                    Forms\Components\Placeholder::make('total_display')
                        ->label('TOTAL a devolver')
                        ->content(fn (Forms\Get $get) => '$ '.number_format(
                            collect($get('lines') ?? [])->sum(fn ($l) => (float) ($l['total'] ?? 0)), 2)),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas internas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    protected static function recomputeLine(Forms\Set $set, Forms\Get $get): void
    {
        $qty = (float) ($get('quantity') ?? 0);
        $unitCost = (float) ($get('unit_cost') ?? 0);

        $subtotal = round($qty * $unitCost, 2);

        $taxRate = 0;
        $taxAmount = 0;
        if ($taxId = $get('tax_id')) {
            $tax = Tax::find($taxId);
            if ($tax) {
                $taxRate = (float) $tax->rate;
                $taxAmount = round($subtotal * ($taxRate / 100), 2);
            }
        }

        $set('subtotal', $subtotal);
        $set('tax_rate', $taxRate);
        $set('tax_amount', $taxAmount);
        $set('total', $subtotal + $taxAmount);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (PurchaseReturn $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('number', 'like', "%{$s}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')->label('Proveedor')->wrap()->searchable(),

                Tables\Columns\TextColumn::make('purchaseInvoice.full_number')
                    ->label('Factura origen')
                    ->state(fn (PurchaseReturn $r) => $r->purchaseInvoice?->fullNumber())
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd()->weight('semibold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $s) => PurchaseReturn::STATUSES[$s] ?? $s)
                    ->badge()
                    ->color(fn (string $s) => match ($s) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(PurchaseReturn::STATUSES),
                Tables\Filters\SelectFilter::make('location_id')->label('Sede')
                    ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (PurchaseReturn $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseReturns::route('/'),
            'create' => Pages\CreatePurchaseReturn::route('/create'),
            'view' => Pages\ViewPurchaseReturn::route('/{record}'),
            'edit' => Pages\EditPurchaseReturn::route('/{record}/edit'),
        ];
    }
}
