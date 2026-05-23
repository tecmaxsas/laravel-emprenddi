<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\InventoryAdjustmentResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\InventoryEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryAdjustmentResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'inventory.view'; }
    protected static function managePermission(): string { return 'inventory.adjust'; }

    protected static ?string $model = InventoryAdjustment::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Ajustes';

    protected static ?string $modelLabel = 'Ajuste de inventario';

    protected static ?string $pluralModelLabel = 'Ajustes de inventario';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('AJ')
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

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->live()
                        ->options(fn () => Location::query()
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\Select::make('direction')
                        ->label('Tipo de ajuste')
                        ->required()
                        ->live()
                        ->options(InventoryAdjustment::DIRECTIONS)
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\Select::make('reason_code')
                        ->label('Motivo')
                        ->required()
                        ->options(InventoryAdjustment::REASONS)
                        ->default('other')
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\Select::make('counterpart_account_id')
                        ->label('Cuenta contraparte')
                        ->required()
                        ->searchable()
                        ->live()
                        ->helperText(fn (Forms\Get $get) => $get('direction') === 'in'
                            ? 'Cuenta de ingreso/recuperación (CR). Sugerido: 4255 Recuperaciones.'
                            : 'Cuenta de pérdida/gasto (DR). Sugerido: 5295 Diversos o 6135 Costo de mercancías perdidas.')
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::find($value)?->fullName())
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('reason_description')
                        ->label('Descripción del motivo')
                        ->placeholder('Ej. Mercancía dañada en bodega tras inundación')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Productos a ajustar')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->relationship('lines')
                        ->orderColumn('line_number')
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
                                ->columnSpan(5),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cantidad')
                                ->numeric()
                                ->minValue(0.0001)
                                ->required()
                                ->default(1)
                                ->live(onBlur: true)
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('unit_cost')
                                ->label('Costo unit.')
                                ->numeric()
                                ->prefix('$')
                                ->minValue(0)
                                ->default(0)
                                ->required(fn (Forms\Get $get) => $get('../../direction') === 'in')
                                ->visible(fn (Forms\Get $get) => $get('../../direction') === 'in')
                                ->columnSpan(2),

                            Forms\Components\Placeholder::make('current_stock')
                                ->label('Stock actual')
                                ->content(function (Forms\Get $get) {
                                    $productId = $get('product_id');
                                    $locId = $get('../../location_id');
                                    if (! $productId || ! $locId) return '—';
                                    return number_format(
                                        app(InventoryEngine::class)->currentStock((int) $productId, (int) $locId),
                                        2
                                    );
                                })
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('notes')
                                ->label('Notas de la línea')
                                ->maxLength(200)
                                ->columnSpanFull(),
                        ])
                        ->columns(12)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('+ Añadir producto')
                        ->reorderableWithButtons(),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas generales')
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
                    ->state(fn (InventoryAdjustment $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $q, string $s) => $q->where('number', 'like', "%{$s}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->wrap(),

                Tables\Columns\TextColumn::make('direction')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $s) => InventoryAdjustment::DIRECTIONS[$s] ?? $s)
                    ->badge()
                    ->color(fn (string $s) => $s === 'in' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('reason_code')
                    ->label('Motivo')
                    ->formatStateUsing(fn (string $s) => InventoryAdjustment::REASONS[$s] ?? $s)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Líneas')
                    ->counts('lines')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $s) => InventoryAdjustment::STATUSES[$s] ?? $s)
                    ->badge()
                    ->color(fn (string $s) => match ($s) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                    }),

                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Contabilizado')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(InventoryAdjustment::STATUSES),
                Tables\Filters\SelectFilter::make('direction')->label('Tipo')->options(InventoryAdjustment::DIRECTIONS),
                Tables\Filters\SelectFilter::make('location_id')->label('Sede')
                    ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (InventoryAdjustment $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryAdjustments::route('/'),
            'create' => Pages\CreateInventoryAdjustment::route('/create'),
            'view' => Pages\ViewInventoryAdjustment::route('/{record}'),
            'edit' => Pages\EditInventoryAdjustment::route('/{record}/edit'),
        ];
    }
}
