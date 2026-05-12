<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\InventoryEngine;
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

/**
 * Matriz producto × sede con el stock actual de cada celda. Una fila por
 * producto, columnas dinámicas con las sedes activas, columna final con
 * el total agregado.
 */
class StockByLocationPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Stock por Sede';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Stock por Sede';

    protected static ?int $navigationSort = 45;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('reports.kardex');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'category_id' => null,
            'only_below_min' => false,
            'only_with_stock' => false,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\Select::make('category_id')
                        ->label('Categoría')
                        ->placeholder('Todas')
                        ->options(fn () => Category::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),

                    Forms\Components\Toggle::make('only_with_stock')
                        ->label('Solo con stock > 0')
                        ->live(),

                    Forms\Components\Toggle::make('only_below_min')
                        ->label('Solo por debajo del mínimo')
                        ->helperText('Productos con stock <= min_stock en alguna sede')
                        ->live()
                        ->columnSpan(2),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        $locations = Location::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $stockColumns = [];
        foreach ($locations as $loc) {
            $locId = $loc->id;
            $stockColumns[] = Tables\Columns\TextColumn::make("stock_loc_{$locId}")
                ->label($loc->name)
                ->state(fn (Product $r) => app(InventoryEngine::class)->currentStock($r->id, $locId))
                ->numeric(decimalPlaces: 2)
                ->alignEnd()
                ->color(function (Product $r) use ($locId) {
                    $stock = app(InventoryEngine::class)->currentStock($r->id, $locId);
                    $pl = $r->productLocations->firstWhere('location_id', $locId);
                    $min = (float) ($pl?->min_stock ?? 0);
                    if ($stock <= 0) return 'danger';
                    if ($min > 0 && $stock <= $min) return 'warning';
                    return null;
                });
        }

        return $table
            ->query(function () {
                return Product::query()
                    ->where('track_inventory', true)
                    ->where('active', true)
                    ->when($this->filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
                    ->with(['productLocations']);
            })
            ->modifyQueryUsing(function (Builder $query) {
                if ($this->filters['only_with_stock'] ?? false) {
                    $query->whereExists(function ($q) {
                        $q->from('inventory_movements as im')
                          ->select(\Illuminate\Support\Facades\DB::raw(1))
                          ->whereColumn('im.product_id', 'products.id')
                          ->whereRaw('im.id IN (SELECT MAX(id) FROM inventory_movements WHERE product_id = products.id GROUP BY location_id)')
                          ->where('im.balance_quantity_after', '>', 0);
                    });
                }
            })
            ->defaultSort('name')
            ->defaultPaginationPageOption(50)
            ->columns(array_merge(
                [
                    Tables\Columns\TextColumn::make('code')
                        ->label('Código')
                        ->fontFamily('mono')
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('name')
                        ->label('Producto')
                        ->wrap()
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('category.name')
                        ->label('Categoría')
                        ->toggleable(),
                ],
                $stockColumns,
                [
                    Tables\Columns\TextColumn::make('stock_total')
                        ->label('Total')
                        ->state(function (Product $r) use ($locations) {
                            $sum = 0;
                            foreach ($locations as $loc) {
                                $sum += app(InventoryEngine::class)->currentStock($r->id, $loc->id);
                            }
                            return $sum;
                        })
                        ->numeric(decimalPlaces: 2)
                        ->alignEnd()
                        ->weight('bold')
                        ->color(fn ($state) => $state <= 0 ? 'danger' : 'success'),
                ]
            ));
    }
}
