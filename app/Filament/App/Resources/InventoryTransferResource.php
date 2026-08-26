<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\InventoryTransferResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\InventoryTransfer;
use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\InventoryEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryTransferResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'inventory.view'; }
    protected static function managePermission(): string { return 'inventory.transfer'; }

    protected static ?string $model = InventoryTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Transferencias';

    protected static ?string $modelLabel = 'Transferencia';

    protected static ?string $pluralModelLabel = 'Transferencias';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('TR')
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

                    Forms\Components\Select::make('from_location_id')
                        ->label('Sede origen')
                        ->required()
                        ->live()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\Select::make('to_location_id')
                        ->label('Sede destino')
                        ->required()
                        ->live()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->different('from_location_id')
                        ->validationMessages(['different' => 'La sede destino debe ser distinta a la origen.'])
                        ->native(false)
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('Productos a transferir')
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
                                    ->where('company_id', auth()->user()?->company_id)
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
                                ->columnSpan(6),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Cantidad')
                                ->numeric()
                                ->minValue(0.0001)
                                ->required()
                                ->default(1)
                                ->live(onBlur: true)
                                ->columnSpan(3),

                            Forms\Components\Placeholder::make('current_stock')
                                ->label('Stock disponible (origen)')
                                ->content(function (Forms\Get $get) {
                                    $productId = $get('product_id');
                                    $fromLocId = $get('../../from_location_id');
                                    if (! $productId || ! $fromLocId) return '—';
                                    $stock = app(InventoryEngine::class)->currentStock((int) $productId, (int) $fromLocId);
                                    return number_format($stock, 2);
                                })
                                ->columnSpan(3),

                            Forms\Components\TextInput::make('notes')
                                ->label('Notas')
                                ->maxLength(200)
                                ->columnSpan(12),
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
                    ->state(fn (InventoryTransfer $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $q, string $state) => $q->where('number', 'like', "%{$state}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('fromLocation.name')->label('Origen')->wrap(),
                Tables\Columns\TextColumn::make('toLocation.name')->label('Destino')->wrap(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Productos')
                    ->counts('lines')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => InventoryTransfer::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                    }),

                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Contabilizada')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('createdBy.name')->label('Creó')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(InventoryTransfer::STATUSES),

                Tables\Filters\SelectFilter::make('from_location_id')
                    ->label('Sede origen')
                    ->options(fn () => Location::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')->pluck('name', 'id')->all()),

                Tables\Filters\SelectFilter::make('to_location_id')
                    ->label('Sede destino')
                    ->options(fn () => Location::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (InventoryTransfer $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryTransfers::route('/'),
            'create' => Pages\CreateInventoryTransfer::route('/create'),
            'view' => Pages\ViewInventoryTransfer::route('/{record}'),
            'edit' => Pages\EditInventoryTransfer::route('/{record}/edit'),
        ];
    }
}
