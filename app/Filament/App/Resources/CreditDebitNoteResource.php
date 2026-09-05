<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CreditDebitNoteResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\CreditDebitNote;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CreditDebitNoteResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'credit_debit_notes.view'; }
    protected static function managePermission(): string { return 'credit_debit_notes.create'; }

    protected static ?string $model = CreditDebitNote::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Notas Crédito / Débito';

    protected static ?string $modelLabel = 'Nota';

    protected static ?string $pluralModelLabel = 'Notas Crédito / Débito';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipo de nota')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(CreditDebitNote::TYPES)
                        ->required()
                        ->live()
                        ->default('credit')
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('prefix', $state === 'credit' ? 'NC' : 'ND')),

                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('NC')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto al guardar'),
                ]),

            Forms\Components\Section::make('Factura referenciada')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('sale_invoice_id')
                        ->label('Factura original')
                        ->required()
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => SaleInvoice::query()
                            ->where('status', 'posted')
                            ->where(function ($q) use ($search) {
                                $q->where('number', 'like', "%{$search}%");
                            })
                            ->orderByDesc('date')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (SaleInvoice $i) => [
                                $i->id => $i->fullNumber().' — '.$i->customer?->name.' ($'.number_format((float) $i->total, 0, ',', '.').')',
                            ])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => SaleInvoice::find($value)
                            ? SaleInvoice::find($value)->fullNumber().' — '.SaleInvoice::find($value)->customer?->name
                            : null)
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if (! $state) return;
                            $invoice = SaleInvoice::find($state);
                            if (! $invoice) return;
                            // Pre-llena cliente y sede desde la factura
                            $set('third_party_id', $invoice->third_party_id);
                            $set('location_id', $invoice->location_id);
                        })
                        ->columnSpan(2),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->required()
                        ->options(fn (Forms\Get $get) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')),

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
                        ->native(false),
                ]),

            Forms\Components\Section::make('Motivo y configuración')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('reason_code')
                        ->label('Motivo DIAN')
                        ->options(fn (Forms\Get $get) => $get('type') === 'debit'
                            ? CreditDebitNote::DEBIT_REASONS
                            : CreditDebitNote::CREDIT_REASONS)
                        ->required()
                        ->native(false)
                        ->helperText('Código oficial DIAN del motivo de la nota.'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha')
                        ->required()
                        ->default(now()),

                    Forms\Components\TextInput::make('reason_description')
                        ->label('Descripción del motivo')
                        ->maxLength(250)
                        ->placeholder('Detalles adicionales del motivo')
                        ->columnSpan(2),

                    Forms\Components\Toggle::make('affects_inventory')
                        ->label('Devolución física de mercancía')
                        ->helperText('Solo aplica a Notas Crédito. Si está activo, los productos vuelven al inventario al postear.')
                        ->visible(fn (Forms\Get $get) => $get('type') === 'credit')
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
                                    $set('unit_price', (float) $product->default_sale_price);
                                    $set('tax_id', $product->default_sale_tax_id);
                                })
                                ->columnSpan(['default' => 1, 'md' => 6, 'xl' => 4]),

                            Forms\Components\TextInput::make('description')->label('Descripción')->required()->maxLength(250)->columnSpan(['default' => 1, 'md' => 6, 'xl' => 3]),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cant.')
                                ->numeric()->minValue(0)->default(1)->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2]),

                            Forms\Components\TextInput::make('unit_price')
                                ->label('Precio unit.')
                                ->numeric()->minValue(0)->prefix('$')->default(0)->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 3]),

                            Forms\Components\TextInput::make('discount_percentage')
                                ->label('Desc. %')
                                ->numeric()->minValue(0)->maxValue(100)->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeLine($set, $get))
                                ->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2]),

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
                                ->columnSpan(['default' => 1, 'md' => 3, 'xl' => 3]),

                            Forms\Components\TextInput::make('total')->label('Total')->numeric()->prefix('$')->disabled()->dehydrated()->default(0)->columnSpan(['default' => 1, 'md' => 3, 'xl' => 3]),

                            Forms\Components\Hidden::make('subtotal')->default(0),
                            Forms\Components\Hidden::make('discount_amount')->default(0),
                            Forms\Components\Hidden::make('tax_rate')->default(0),
                            Forms\Components\Hidden::make('tax_amount')->default(0),
                        ])
                        ->columns(['default' => 1, 'md' => 6, 'xl' => 20])
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

            Forms\Components\Textarea::make('notes')->label('Notas internas')->rows(2)->columnSpanFull(),
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
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => CreditDebitNote::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => $state === 'credit' ? 'warning' : 'info'),

                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (CreditDebitNote $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $q, string $search) => $q->where('number', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('saleInvoice.full_number')
                    ->label('Factura')
                    ->state(fn (CreditDebitNote $r) => $r->saleInvoice?->fullNumber())
                    ->fontFamily('mono')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->wrap(),

                Tables\Columns\TextColumn::make('reason_code')
                    ->label('Motivo')
                    ->state(fn (CreditDebitNote $r) => $r->reasonLabel())
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')->label('Total')->money('COP')->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => CreditDebitNote::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                    }),

                Tables\Columns\TextColumn::make('dian_status')
                    ->label('DIAN')
                    ->formatStateUsing(fn (?string $state) => $state ? (CreditDebitNote::DIAN_STATUSES[$state] ?? $state) : '—')
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
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(CreditDebitNote::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(CreditDebitNote::STATUSES),
                Tables\Filters\SelectFilter::make('dian_status')->label('DIAN')->options(CreditDebitNote::DIAN_STATUSES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (CreditDebitNote $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditDebitNotes::route('/'),
            'create' => Pages\CreateCreditDebitNote::route('/create'),
            'view' => Pages\ViewCreditDebitNote::route('/{record}'),
            'edit' => Pages\EditCreditDebitNote::route('/{record}/edit'),
        ];
    }
}
