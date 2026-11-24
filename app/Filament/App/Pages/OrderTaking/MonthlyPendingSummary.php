<?php

namespace App\Filament\App\Pages\OrderTaking;

use App\Services\OrderTaking\PendingDeliveries;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consolidado mensual: qué le quedó pendiente a cada cliente en el mes.
 *
 * El informe de no entregas lista línea por línea, que es lo que sirve para
 * perseguir un pedido concreto. Este contesta la otra pregunta, la que se
 * hace a fin de mes mirando el total: a qué clientes les quedamos mal, con
 * qué referencias y por cuánta plata.
 *
 * Por eso agrupa por cliente y luego por referencia. Un mismo cliente puede
 * tener la misma referencia pendiente en tres pedidos distintos del mes;
 * sumadas son lo que hay que reponer, y separadas no dicen nada.
 *
 * No usa tabla de Filament: no hay un modelo por fila —son dos niveles de
 * agrupación— y montarlo con una tabla obligaría a inventar un registro
 * intermedio solo para que el componente tuviera de dónde agarrarse.
 */
class MonthlyPendingSummary extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Consolidado de pendientes';

    protected static ?string $navigationGroup = 'Toma pedidos';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'order-taking/monthly-pending';

    protected static ?string $title = 'Consolidado mensual de pendientes por cliente';

    protected static string $view = 'filament.app.pages.order-taking.monthly-pending';

    public ?array $filters = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('order_taking')) {
            return false;
        }

        return (bool) auth()->user()?->can('order_taking.use');
    }

    public function mount(): void
    {
        $this->filters = [
            'mes' => now()->format('Y-m'),
            'cerrados' => 'todos',
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Período')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('mes')
                        ->label('Mes')
                        ->native(false)
                        ->live()
                        ->options(fn () => $this->mesesDisponibles())
                        ->selectablePlaceholder(false),

                    Forms\Components\Select::make('cerrados')
                        ->label('Pedidos cerrados')->native(false)->live()
                        ->options([
                            'todos' => 'Incluirlos',
                            'excluir' => 'Solo lo que aún se puede despachar',
                            'solo' => 'Solo los cerrados con faltantes',
                        ])
                        ->default('todos')
                        ->selectablePlaceholder(false),
                ]),
        ])->statePath('filters');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getConsolidadoProperty(): Collection
    {
        return app(PendingDeliveries::class)->consolidadoMensual(
            $this->mes(),
            $this->filtrosDeCierre(),
        );
    }

    /** @return array{clientes: int, referencias: int, unidades: float, valor: float} */
    public function getTotalesProperty(): array
    {
        $consolidado = $this->consolidado;

        return [
            'clientes' => $consolidado->count(),
            'referencias' => $consolidado->sum(fn (array $c) => count($c['lineas'])),
            'unidades' => round($consolidado->sum('unidades'), 2),
            'valor' => round($consolidado->sum('valor'), 2),
        ];
    }

    public function getExportUrl(): string
    {
        return route('order-taking.reports.export.monthly-pending', $this->filtrosParaUrl());
    }

    public function getPdfUrl(): string
    {
        return route('order-taking.reports.pdf.monthly-pending', $this->filtrosParaUrl());
    }

    public function etiquetaDelMes(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->mes().'-01')
            ->locale('es')
            ->isoFormat('MMMM [de] YYYY');
    }

    protected function mes(): string
    {
        $mes = (string) ($this->filters['mes'] ?? '');

        return preg_match('/^\d{4}-\d{2}$/', $mes) ? $mes : now()->format('Y-m');
    }

    /** @return array<string, mixed> */
    protected function filtrosDeCierre(): array
    {
        $cerrados = $this->filters['cerrados'] ?? 'todos';

        return [
            'incluir_cerrados' => $cerrados !== 'excluir',
            'solo_cerrados' => $cerrados === 'solo',
        ];
    }

    /** @return array<string, mixed> */
    protected function filtrosParaUrl(): array
    {
        return [
            'mes' => $this->mes(),
            'cerrados' => $this->filters['cerrados'] ?? 'todos',
        ];
    }

    /**
     * Los últimos dos años de meses.
     *
     * Un selector de mes es más seguro que dos fechas sueltas para un
     * informe que, por definición, es mensual: no hay forma de pedir «del 3
     * de marzo al 17 de abril» y después preguntarse por qué las cifras no
     * cuadran con el cierre del mes.
     *
     * @return array<string, string>
     */
    protected function mesesDisponibles(): array
    {
        $opciones = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $opciones[$cursor->format('Y-m')] = ucfirst($cursor->locale('es')->isoFormat('MMMM [de] YYYY'));
            $cursor = $cursor->copy()->subMonth();
        }

        return $opciones;
    }
}
