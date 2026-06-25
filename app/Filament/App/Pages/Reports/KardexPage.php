<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KardexPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Kardex';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Kardex de Inventario';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('reports.kardex');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'product_id' => null,
            'location_id' => null,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'types' => [],
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        if (empty($this->filters['product_id']) || empty($this->filters['location_id'])) {
            return null;
        }
        return route('reports.export.kardex', array_filter([
            'product_id' => $this->filters['product_id'],
            'location_id' => $this->filters['location_id'],
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'types' => ! empty($this->filters['types']) ? implode(',', $this->filters['types']) : null,
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Filtros')
                    ->columns(4)
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Producto')
                            ->required()
                            ->live()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Product::query()
                                ->where('active', true)
                                ->where(function ($q) use ($search) {
                                    $q->where('code', 'ilike', "%{$search}%")
                                      ->orWhere('name', 'ilike', "%{$search}%")
                                      ->orWhere('barcode', 'ilike', "%{$search}%");
                                })
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Product $p) => [$p->id => "{$p->code} — {$p->name}"])
                                ->all())
                            ->getOptionLabelUsing(fn ($value) => Product::find($value)
                                ? Product::find($value)->code.' — '.Product::find($value)->name
                                : null)
                            ->columnSpan(2),

                        Forms\Components\Select::make('location_id')
                            ->label('Sede')
                            ->required()
                            ->live()
                            ->options(fn () => Location::query()
                                ->where('company_id', auth()->user()?->company_id)
                                ->where('active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Location $l) => [$l->id => $l->fullName()])
                                ->all())
                            ->native(false)
                            ->columnSpan(2),

                        Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                        Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),

                        Forms\Components\Select::make('types')
                            ->label('Tipos de movimiento')
                            ->multiple()
                            ->placeholder('Todos')
                            ->options(InventoryMovement::TYPES)
                            ->live()
                            ->columnSpan(4),
                    ]),
            ])
            ->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $productId = $this->filters['product_id'] ?? null;
                $locationId = $this->filters['location_id'] ?? null;

                if (! $productId || ! $locationId) {
                    return InventoryMovement::query()->whereRaw('1 = 0');
                }

                return InventoryMovement::query()
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->whereDate('date', '>=', $this->filters['from'])
                    ->whereDate('date', '<=', $this->filters['to'])
                    ->when(! empty($this->filters['types']), fn ($q) => $q->whereIn('type', $this->filters['types']))
                    ->with(['thirdParty', 'journalEntry'])
                    ->orderBy('date')
                    ->orderBy('id');
            })
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Fecha')->dateTime('Y-m-d H:i'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => InventoryMovement::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (InventoryMovement $record) => $record->isEntry() ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Ref.')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('thirdParty.name')
                    ->label('Tercero')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Concepto')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('quantity_in')
                    ->label('Entrada')
                    ->state(fn (InventoryMovement $r) => $r->isEntry() ? abs((float) $r->quantity) : null)
                    ->placeholder('—')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total entradas')
                            ->using(fn ($query) => $query
                                ->whereIn('type', InventoryMovement::ENTRY_TYPES)
                                ->sum(\Illuminate\Support\Facades\DB::raw('ABS(quantity)')))
                            ->numeric(decimalPlaces: 2)
                    ),

                Tables\Columns\TextColumn::make('quantity_out')
                    ->label('Salida')
                    ->state(fn (InventoryMovement $r) => $r->isExit() ? abs((float) $r->quantity) : null)
                    ->placeholder('—')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Total salidas')
                            ->using(fn ($query) => $query
                                ->whereIn('type', InventoryMovement::EXIT_TYPES)
                                ->sum(\Illuminate\Support\Facades\DB::raw('ABS(quantity)')))
                            ->numeric(decimalPlaces: 2)
                    ),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Costo unit.')
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total')
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('balance_quantity_after')
                    ->label('Saldo qty')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('balance_value_after')
                    ->label('Saldo $')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('journalEntry.full_number')
                    ->label('Asiento')
                    ->state(fn (InventoryMovement $r) => $r->journalEntry?->fullNumber())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->fontFamily('mono'),
            ]);
    }
}
