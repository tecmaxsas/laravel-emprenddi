<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Category;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Matriz producto × sede con el stock actual de cada celda. Una fila por
 * producto, columnas dinámicas con las sedes activas, columna final con
 * el total agregado.
 *
 * Performance: pre-carga TODAS las balanzas de la empresa en una sola
 * query usando DISTINCT ON (Postgres) sobre inventory_movements, y las
 * cachea en una propiedad estática del proceso. Cada celda hace lookup
 * O(1) en lugar de pegarle a la DB. En MySQL/SQLite usa un fallback con
 * subquery correlated (MAX(id) por product+location).
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

    /** @var array<int,array<int,float>>|null Cache de la matriz por render */
    protected ?array $stockMatrixCache = null;

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
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
                        ->live()
                        ->afterStateUpdated(fn () => $this->stockMatrixCache = null),

                    Forms\Components\Toggle::make('only_with_stock')
                        ->label('Solo con stock > 0')
                        ->live()
                        ->afterStateUpdated(fn () => $this->stockMatrixCache = null),

                    Forms\Components\Toggle::make('only_below_min')
                        ->label('Solo por debajo del mínimo')
                        ->helperText('Productos con stock <= min_stock en alguna sede')
                        ->live()
                        ->afterStateUpdated(fn () => $this->stockMatrixCache = null)
                        ->columnSpan(2),
                ]),
        ])->statePath('filters');
    }

    /**
     * Carga la matriz [productId => [locationId => balance]] de la
     * empresa actual en una sola query. Cacheada por render.
     */
    protected function stockMatrix(): array
    {
        if ($this->stockMatrixCache !== null) {
            return $this->stockMatrixCache;
        }

        $companyId = Auth::user()?->company_id;
        if (! $companyId) {
            return $this->stockMatrixCache = [];
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Postgres: DISTINCT ON es el camino más rápido. Toma la última
            // fila por (product, location) en una sola pasada.
            $rows = DB::table('inventory_movements')
                ->select('product_id', 'location_id', 'balance_quantity_after')
                ->where('company_id', $companyId)
                ->orderBy('product_id')
                ->orderBy('location_id')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->distinct('product_id', 'location_id')
                ->get();
        } else {
            // Fallback genérico para MySQL/SQLite: join con la subquery
            // MAX(id) por (product, location). Aún es una sola query.
            $rows = DB::table('inventory_movements as im')
                ->select('im.product_id', 'im.location_id', 'im.balance_quantity_after')
                ->joinSub(
                    DB::table('inventory_movements')
                        ->selectRaw('product_id, location_id, MAX(id) AS max_id')
                        ->where('company_id', $companyId)
                        ->groupBy('product_id', 'location_id'),
                    'last',
                    fn ($join) => $join->on('im.id', '=', 'last.max_id')
                )
                ->get();
        }

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(int) $row->product_id][(int) $row->location_id] = (float) $row->balance_quantity_after;
        }

        return $this->stockMatrixCache = $matrix;
    }

    /**
     * Lookup O(1) en la matriz. Devuelve 0 si no hay movimiento previo
     * para ese (producto, sede).
     */
    protected function stockAt(int $productId, int $locationId): float
    {
        return $this->stockMatrix()[$productId][$locationId] ?? 0.0;
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
                ->state(fn (Product $r) => $this->stockAt($r->id, $locId))
                ->numeric(decimalPlaces: 2)
                ->alignEnd()
                ->color(function (Product $r) use ($locId) {
                    $stock = $this->stockAt($r->id, $locId);
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
                // Filtro "solo con stock" — IDs sacados de la matriz pre-cargada.
                if ($this->filters['only_with_stock'] ?? false) {
                    $matrix = $this->stockMatrix();
                    $ids = [];
                    foreach ($matrix as $productId => $byLoc) {
                        foreach ($byLoc as $bal) {
                            if ($bal > 0) {
                                $ids[$productId] = true;
                                break;
                            }
                        }
                    }
                    $query->whereIn('id', array_keys($ids) ?: [0]);
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
                        ->state(function (Product $r) {
                            return array_sum($this->stockMatrix()[$r->id] ?? []);
                        })
                        ->numeric(decimalPlaces: 2)
                        ->alignEnd()
                        ->weight('bold')
                        ->color(fn ($state) => $state <= 0 ? 'danger' : 'success'),
                ]
            ));
    }
}
