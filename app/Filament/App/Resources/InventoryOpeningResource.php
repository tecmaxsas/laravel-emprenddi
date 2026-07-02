<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\InventoryOpeningResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\InventoryOpening;
use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\InventoryEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryOpeningResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'inventory.view'; }
    protected static function managePermission(): string { return 'inventory.adjust'; }

    protected static ?string $model = InventoryOpening::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static ?string $navigationLabel = 'Saldo inicial';

    protected static ?string $modelLabel = 'Apertura de inventario';

    protected static ?string $pluralModelLabel = 'Aperturas de inventario';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('SI')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto al guardar'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha de apertura')
                        ->required()
                        ->default(now())
                        ->columnSpan(2),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
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

                    Forms\Components\Select::make('counterpart_account_id')
                        ->label('Cuenta contraparte (CR)')
                        ->required()
                        ->searchable()
                        ->helperText('Sugerido: 3705 Resultados de ejercicios anteriores, 3115 Capital social, o 1305 Cuentas por cobrar si se compensa con otra entidad.')
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
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
                ]),

            Forms\Components\Section::make('Productos a cargar')
                ->description('Cantidad y costo unitario inicial por producto. El sistema asume método promedio ponderado.')
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
                                ->minValue(0.01)
                                ->required()
                                ->prefix('$')
                                ->columnSpan(2),

                            Forms\Components\Placeholder::make('current_stock')
                                ->label('Stock actual en sede')
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
                ->label('Notas')
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
                    ->state(fn (InventoryOpening $r) => $r->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $q, string $state) => $q->where('number', 'like', "%{$state}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('location.name')->label('Sede')->wrap(),

                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Productos')
                    ->counts('lines')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('counterpartAccount.code')
                    ->label('Contraparte')
                    ->state(fn (InventoryOpening $r) => $r->counterpartAccount?->code)
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => InventoryOpening::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
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
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(InventoryOpening::STATUSES),
                Tables\Filters\SelectFilter::make('location_id')->label('Sede')
                    ->options(fn () => Location::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (InventoryOpening $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryOpenings::route('/'),
            'create' => Pages\CreateInventoryOpening::route('/create'),
            'view' => Pages\ViewInventoryOpening::route('/{record}'),
            'edit' => Pages\EditInventoryOpening::route('/{record}/edit'),
        ];
    }
}
