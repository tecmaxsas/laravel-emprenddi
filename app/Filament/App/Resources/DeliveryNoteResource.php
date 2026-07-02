<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\DeliveryNoteResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\DeliveryNote;
use App\Models\Location;
use App\Models\Product;
use App\Models\ThirdParty;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DeliveryNoteResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'delivery_notes.view'; }
    protected static function managePermission(): string { return 'delivery_notes.manage'; }

    protected static ?string $model = DeliveryNote::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Remisiones';

    protected static ?string $modelLabel = 'Remisión';

    protected static ?string $pluralModelLabel = 'Remisiones';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('REM')
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

                    Forms\Components\Select::make('location_id')
                        ->label('Sede que despacha')
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

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha de despacho')
                        ->required()
                        ->default(now()),
                ]),

            Forms\Components\Section::make('Transporte')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('carrier')->label('Transportador')->maxLength(150),
                    Forms\Components\TextInput::make('vehicle_plate')->label('Placa vehículo')->maxLength(30),
                    Forms\Components\TextInput::make('driver_name')->label('Conductor')->maxLength(150),
                    Forms\Components\TextInput::make('destination_address')
                        ->label('Dirección de entrega')
                        ->maxLength(250)
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('Productos a despachar')
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
                                })
                                ->columnSpan(5),

                            Forms\Components\TextInput::make('description')
                                ->label('Descripción')
                                ->required()
                                ->maxLength(250)
                                ->columnSpan(5),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cantidad')
                                ->numeric()
                                ->minValue(0)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => $set(
                                    'subtotal',
                                    round((float) $get('quantity') * (float) $get('unit_price'), 2)
                                ))
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('unit_price')
                                ->label('Precio ref.')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('$')
                                ->default(0)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => $set(
                                    'subtotal',
                                    round((float) $get('quantity') * (float) $get('unit_price'), 2)
                                ))
                                ->helperText('Informativo. La factura posterior maneja los precios reales.')
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('subtotal')
                                ->label('Subtotal')
                                ->numeric()
                                ->prefix('$')
                                ->disabled()
                                ->dehydrated()
                                ->default(0)
                                ->columnSpan(2),
                        ])
                        ->columns(16)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->live()
                        ->addActionLabel('+ Añadir línea')
                        ->reorderableWithButtons(),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas / Observaciones')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (DeliveryNote $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $q, string $search) => $q->where('number', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('location.name')->label('Sede')->toggleable(),
                Tables\Columns\TextColumn::make('seller.name')->label('Vendedor')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Items')
                    ->counts('lines')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => DeliveryNote::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'dispatched' => 'info',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        'billed' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('billedAtSaleInvoice.full_number')
                    ->label('Factura')
                    ->state(fn (DeliveryNote $r) => $r->billedAtSaleInvoice?->fullNumber())
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(DeliveryNote::STATUSES),
                Tables\Filters\SelectFilter::make('location_id')->label('Sede')->relationship('location', 'name'),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Desde'),
                        Forms\Components\DatePicker::make('to')->label('Hasta'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (DeliveryNote $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryNotes::route('/'),
            'create' => Pages\CreateDeliveryNote::route('/create'),
            'view' => Pages\ViewDeliveryNote::route('/{record}'),
            'edit' => Pages\EditDeliveryNote::route('/{record}/edit'),
        ];
    }
}
