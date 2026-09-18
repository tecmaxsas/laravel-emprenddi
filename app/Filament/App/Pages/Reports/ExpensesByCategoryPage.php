<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Support\AccountantContext;
use App\Support\ExpensesByCategory;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * En qué se le fue la plata al negocio.
 *
 * La única forma de agrupar gastos era la cuenta contable del PUC, que le
 * sirve al contador pero no al dueño: «5195 - Diversos» no le dice si se le
 * fue en domicilios o en mantenimiento. Y una empresa sin el módulo de
 * contabilidad no tenía ni siquiera eso.
 *
 * Los gastos sin categoría salen en su propia fila en vez de quedarse por
 * fuera: así se ve cuánto falta por clasificar en vez de que el total del
 * reporte no cuadre contra el listado de gastos.
 */
class ExpensesByCategoryPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Gastos por Categoría';

    protected static ?string $navigationGroup = 'Reportes operativos';

    protected static ?string $title = 'Gastos por Categoría';

    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! AccountantContext::ready()) {
            return false;
        }

        return (bool) auth()->user()?->can('expenses.view');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'location_id' => null,
            'expense_category_id' => null,
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('reports.export.expenses_by_category', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'location_id' => $this->filters['location_id'] ?? null,
            'expense_category_id' => $this->filters['expense_category_id'] ?? null,
        ]));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->description('Cuenta solo los gastos contabilizados: un borrador es una intención, no plata gastada. Los gastos sin categoría aparecen en su propia fila para que se vea cuánto falta por clasificar.')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->placeholder('Todas')
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),

                    Forms\Components\Select::make('expense_category_id')
                        ->label('Categoría')
                        ->placeholder('Todas')
                        ->options(fn () => ExpenseCategory::opciones()
                            + ['sin_categoria' => '— Sin categoría —'])
                        ->searchable()
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ExpensesByCategory::agrupado($this->filtrosActuales()))
            ->defaultSort('total', 'desc')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('categoria')
                    ->label('Categoría')
                    ->weight('semibold')
                    ->formatStateUsing(fn (?string $state) => $state ?: ExpensesByCategory::SIN_CATEGORIA)
                    ->color(fn (Expense $r) => $r->expense_category_id ? null : 'warning')
                    ->description(fn (Expense $r) => $r->expense_category_id
                        ? null
                        : 'Estos gastos no se sabe en qué se fueron'),

                Tables\Columns\TextColumn::make('movimientos')
                    ->label('N° de gastos')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total gastado')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('participacion')
                    ->label('% del total')
                    ->alignEnd()
                    ->state(function (Expense $r) {
                        $total = $this->totalGastado();

                        if ($total <= 0) {
                            return '—';
                        }

                        return number_format((float) $r->total / $total * 100, 1, ',', '.').' %';
                    }),
            ])
            ->emptyStateHeading('No hay gastos contabilizados en este período')
            ->emptyStateDescription('Un gasto en borrador no cuenta hasta que se contabiliza.')
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->resumen())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->disabled(),
            ]);
    }

    /** @return array<string, mixed> */
    protected function filtrosActuales(): array
    {
        return [
            'from' => $this->filters['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $this->filters['to'] ?? now()->endOfMonth()->toDateString(),
            'location_id' => $this->filters['location_id'] ?? null,
            'expense_category_id' => $this->filters['expense_category_id'] ?? null,
        ];
    }

    /**
     * El total del período, para el porcentaje de cada fila.
     *
     * Se guarda porque esa columna se evalúa una vez por fila y sin esto cada
     * línea repetiría la misma suma sobre toda la tabla.
     */
    private ?float $totalCache = null;

    protected function totalGastado(): float
    {
        return $this->totalCache ??= ExpensesByCategory::total($this->filtrosActuales());
    }

    protected function resumen(): string
    {
        $filtros = $this->filtrosActuales();

        return sprintf(
            '%d gasto(s) — $%s',
            ExpensesByCategory::movimientos($filtros),
            number_format(ExpensesByCategory::total($filtros), 0, ',', '.'),
        );
    }
}
