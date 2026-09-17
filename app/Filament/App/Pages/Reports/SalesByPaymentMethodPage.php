<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Location;
use App\Models\Payment;
use App\Models\User;
use App\Support\AccountantContext;
use App\Support\PaymentMethodOptions;
use App\Support\SalesByPaymentMethod;
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
 * Cuánta plata entró por cada forma de pago.
 *
 * Es la pregunta que se hace todo el que cierra el día: del total vendido,
 * cuánto fue efectivo, cuánto tarjeta, cuánto Nequi. Hasta ahora había que
 * abrir cada cierre de caja y sumar a mano.
 *
 * Va por la **fecha del pago**, no por la de la factura: una venta a crédito
 * aparece el día que el cliente paga, que es el día que la plata entró de
 * verdad. Si fuera por fecha de factura, el total del reporte no cuadraría
 * nunca contra el arqueo ni contra el extracto del banco.
 *
 * Los nombres salen de los métodos que la empresa configuró —Nequi, Daviplata,
 * el convenio propio— y no de la lista de fábrica.
 */
class SalesByPaymentMethodPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Ventas por Método de Pago';

    protected static ?string $navigationGroup = 'Reportes operativos';

    protected static ?string $title = 'Ventas por Método de Pago';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! AccountantContext::ready()) {
            return false;
        }

        return (bool) auth()->user()?->can('reports.sales');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'location_id' => null,
            'created_by_user_id' => null,
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('reports.export.sales_by_payment_method', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'location_id' => $this->filters['location_id'] ?? null,
            'created_by_user_id' => $this->filters['created_by_user_id'] ?? null,
        ]));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->description('Cuenta los pagos aplicados a facturas de venta según la fecha del pago: una venta a crédito aparece el día que el cliente paga, no el día que se facturó. Cuando el abono viene de un anticipo, se muestra con el método con que el cliente entregó esa plata. Un anticipo que todavía no se ha aplicado a ninguna factura no aparece aquí.')
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

                    Forms\Components\Select::make('created_by_user_id')
                        ->label('Registrado por')
                        ->placeholder('Todos')
                        ->options(fn () => User::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => SalesByPaymentMethod::agrupado($this->filtrosActuales()))
            ->defaultSort('total', 'desc')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método de pago')
                    ->formatStateUsing(fn (?string $state) => PaymentMethodOptions::nombre($state))
                    ->weight('semibold')
                    ->description(fn (Payment $r) => $r->payment_method),

                Tables\Columns\TextColumn::make('operaciones')
                    ->label('N° de pagos')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total recaudado')
                    ->money('COP')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('participacion')
                    ->label('% del total')
                    ->alignEnd()
                    ->state(function (Payment $r) {
                        $total = $this->totalRecaudado();

                        if ($total <= 0) {
                            return '—';
                        }

                        return number_format((float) $r->total / $total * 100, 1, ',', '.').' %';
                    }),
            ])
            ->emptyStateHeading('No se registraron pagos en este período')
            ->emptyStateDescription('Recuerda que el reporte va por la fecha del pago. Si vendiste a crédito, el cobro aparece el día que el cliente paga.')
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
            'created_by_user_id' => $this->filters['created_by_user_id'] ?? null,
        ];
    }

    /**
     * El total del período, para calcular el porcentaje de cada fila.
     *
     * Se guarda porque la columna de participación se evalúa una vez por fila y
     * sin esto cada línea repetiría la misma suma sobre toda la tabla.
     */
    private ?float $totalCache = null;

    protected function totalRecaudado(): float
    {
        return $this->totalCache ??= SalesByPaymentMethod::total($this->filtrosActuales());
    }

    protected function resumen(): string
    {
        $total = SalesByPaymentMethod::total($this->filtrosActuales());
        $operaciones = SalesByPaymentMethod::operaciones($this->filtrosActuales());

        return sprintf(
            '%d pago(s) — $%s recaudado',
            $operaciones,
            number_format($total, 0, ',', '.'),
        );
    }
}
