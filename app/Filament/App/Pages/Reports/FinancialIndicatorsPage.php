<?php

namespace App\Filament\App\Pages\Reports;

use App\Services\Reports\FinancialStatementsEngine;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Indicadores financieros (razones) calculados a partir del ERI y el ESF.
 * Cuatro grupos: Liquidez, Rentabilidad, Endeudamiento, Actividad.
 */
class FinancialIndicatorsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Indicadores Financieros';
    protected static ?string $navigationGroup = 'Reportes contables';
    protected static ?string $title = 'Indicadores Financieros';
    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.app.pages.reports.financial-indicators';

    public ?array $filters = [];
    public array $indicators = [];

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        // Contable: se oculta si la empresa no tiene el modulo.
        if (! \App\Support\ModuleGate::active(\App\Support\ModuleGate::ACCOUNTING)) return false;
        return (bool) auth()->user()?->can('reports.financial_indicators');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfYear()->toDateString(),
            'to' => now()->toDateString(),
        ];
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Período de análisis')
                    ->description('El balance se calcula al "hasta"; los indicadores que usan flujo (ventas, utilidad) usan todo el rango.')
                    ->columns(4)
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label('Desde')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshData()),
                        Forms\Components\DatePicker::make('to')
                            ->label('Hasta (fecha de corte del balance)')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshData()),
                        Forms\Components\Placeholder::make('preset_hint')
                            ->label('Períodos rápidos')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div style="display:flex; gap:6px; flex-wrap:wrap;">'
                                .'<button type="button" wire:click="presetMonth" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Mes</button>'
                                .'<button type="button" wire:click="presetYTD" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Año en curso</button>'
                                .'<button type="button" wire:click="presetLastYear" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Año anterior</button>'
                                .'</div>'
                            ))
                            ->columnSpan(2),
                    ]),
            ])
            ->statePath('filters');
    }

    public function presetMonth(): void
    {
        $this->filters['from'] = now()->startOfMonth()->toDateString();
        $this->filters['to'] = now()->endOfMonth()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function presetYTD(): void
    {
        $this->filters['from'] = now()->startOfYear()->toDateString();
        $this->filters['to'] = now()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function presetLastYear(): void
    {
        $this->filters['from'] = now()->subYear()->startOfYear()->toDateString();
        $this->filters['to'] = now()->subYear()->endOfYear()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    protected function refreshData(): void
    {
        $companyId = auth()->user()->company_id;
        $from = $this->filters['from'] ?? now()->startOfYear()->toDateString();
        $to = $this->filters['to'] ?? now()->toDateString();

        $engine = app(FinancialStatementsEngine::class);
        $eri = $engine->incomeStatement($companyId, $from, $to);
        $esf = $engine->balanceSheet($companyId, $to);

        $this->indicators = $this->computeIndicators($eri, $esf);
    }

    /** Construye el mapa de indicadores agrupados por categoría. */
    protected function computeIndicators(array $eri, array $esf): array
    {
        $sales       = (float) $eri['totals']['revenue_operating'];
        $cogs        = (float) $eri['totals']['cogs'];
        $grossProfit = (float) $eri['totals']['gross_profit'];
        $ebit        = (float) $eri['totals']['operating_result'];
        $net         = (float) $eri['totals']['net_result'];
        $finExp      = (float) $eri['totals']['financial_expenses'];

        $assetsTotal      = (float) $esf['assets']['total'];
        $assetsCurrent    = (float) $esf['assets']['current_total'];
        $liabTotal        = (float) $esf['liabilities']['total'];
        $liabCurrent      = (float) $esf['liabilities']['current_total'];
        $equityTotal      = (float) $esf['equity']['total'];

        // Inventarios y CxC desde el detalle del activo corriente
        $inventory = $this->sumPrefix($esf['assets']['current'], '14');
        $receivables = $this->sumPrefix($esf['assets']['current'], '13');

        $safe = fn ($num, $den) => $den != 0 ? $num / $den : 0;

        return [
            [
                'title' => 'Liquidez',
                'color' => '#0ea5e9',
                'description' => '¿Puede pagar sus deudas a corto plazo?',
                'items' => [
                    [
                        'name' => 'Razón corriente',
                        'formula' => 'Activo corriente / Pasivo corriente',
                        'value' => $safe($assetsCurrent, $liabCurrent),
                        'format' => 'ratio',
                        'benchmark' => 'Ideal > 1.5',
                        'status' => $this->statusFromThresholds($safe($assetsCurrent, $liabCurrent), 1.5, 1.0),
                    ],
                    [
                        'name' => 'Prueba ácida',
                        'formula' => '(AC − Inventarios) / Pasivo corriente',
                        'value' => $safe($assetsCurrent - $inventory, $liabCurrent),
                        'format' => 'ratio',
                        'benchmark' => 'Ideal > 1.0',
                        'status' => $this->statusFromThresholds($safe($assetsCurrent - $inventory, $liabCurrent), 1.0, 0.7),
                    ],
                    [
                        'name' => 'Capital de trabajo',
                        'formula' => 'Activo corriente − Pasivo corriente',
                        'value' => $assetsCurrent - $liabCurrent,
                        'format' => 'money',
                        'benchmark' => 'Positivo',
                        'status' => $assetsCurrent - $liabCurrent > 0 ? 'good' : 'bad',
                    ],
                ],
            ],
            [
                'title' => 'Rentabilidad',
                'color' => '#22c55e',
                'description' => '¿Qué tan rentable es el negocio?',
                'items' => [
                    [
                        'name' => 'Margen bruto',
                        'formula' => 'Utilidad bruta / Ventas',
                        'value' => $safe($grossProfit, $sales) * 100,
                        'format' => 'pct',
                        'benchmark' => 'Depende del sector',
                        'status' => $grossProfit > 0 ? 'good' : 'bad',
                    ],
                    [
                        'name' => 'Margen operacional',
                        'formula' => 'EBIT / Ventas',
                        'value' => $safe($ebit, $sales) * 100,
                        'format' => 'pct',
                        'benchmark' => '> 5% sano',
                        'status' => $this->statusFromThresholds($safe($ebit, $sales) * 100, 5, 0),
                    ],
                    [
                        'name' => 'Margen neto',
                        'formula' => 'Utilidad neta / Ventas',
                        'value' => $safe($net, $sales) * 100,
                        'format' => 'pct',
                        'benchmark' => 'Positivo',
                        'status' => $net > 0 ? 'good' : 'bad',
                    ],
                    [
                        'name' => 'ROE',
                        'formula' => 'Utilidad neta / Patrimonio',
                        'value' => $safe($net, $equityTotal) * 100,
                        'format' => 'pct',
                        'benchmark' => '> 15% bueno',
                        'status' => $this->statusFromThresholds($safe($net, $equityTotal) * 100, 15, 0),
                    ],
                    [
                        'name' => 'ROA',
                        'formula' => 'Utilidad neta / Activos totales',
                        'value' => $safe($net, $assetsTotal) * 100,
                        'format' => 'pct',
                        'benchmark' => '> 5% bueno',
                        'status' => $this->statusFromThresholds($safe($net, $assetsTotal) * 100, 5, 0),
                    ],
                ],
            ],
            [
                'title' => 'Endeudamiento',
                'color' => '#f59e0b',
                'description' => '¿Qué tan endeudada está la empresa?',
                'items' => [
                    [
                        'name' => 'Nivel de endeudamiento',
                        'formula' => 'Pasivo total / Activo total',
                        'value' => $safe($liabTotal, $assetsTotal) * 100,
                        'format' => 'pct',
                        'benchmark' => '< 60% sano',
                        'status' => $this->statusFromThresholdsInverse($safe($liabTotal, $assetsTotal) * 100, 60, 80),
                    ],
                    [
                        'name' => 'Apalancamiento',
                        'formula' => 'Pasivo total / Patrimonio',
                        'value' => $safe($liabTotal, $equityTotal),
                        'format' => 'ratio',
                        'benchmark' => '< 1.5 cómodo',
                        'status' => $this->statusFromThresholdsInverse($safe($liabTotal, $equityTotal), 1.5, 3.0),
                    ],
                    [
                        'name' => 'Cobertura de intereses',
                        'formula' => 'EBIT / Gastos financieros',
                        'value' => $safe($ebit, $finExp),
                        'format' => 'ratio',
                        'benchmark' => '> 2x cómodo',
                        'status' => $finExp > 0 ? $this->statusFromThresholds($safe($ebit, $finExp), 2, 1) : 'neutral',
                    ],
                ],
            ],
            [
                'title' => 'Actividad / Eficiencia',
                'color' => '#a855f7',
                'description' => '¿Qué tan bien usa sus recursos?',
                'items' => [
                    [
                        'name' => 'Rotación de cartera',
                        'formula' => 'Ventas / Cuentas por cobrar',
                        'value' => $safe($sales, $receivables),
                        'format' => 'ratio',
                        'benchmark' => 'Más veces = mejor',
                        'status' => 'neutral',
                    ],
                    [
                        'name' => 'Días de cartera',
                        'formula' => '365 / Rotación cartera',
                        'value' => $safe($receivables * 365, $sales),
                        'format' => 'days',
                        'benchmark' => 'Depende de tus plazos',
                        'status' => 'neutral',
                    ],
                    [
                        'name' => 'Rotación de inventario',
                        'formula' => 'Costo de ventas / Inventario',
                        'value' => $safe($cogs, $inventory),
                        'format' => 'ratio',
                        'benchmark' => 'Más alta = mejor',
                        'status' => 'neutral',
                    ],
                    [
                        'name' => 'Días de inventario',
                        'formula' => '365 / Rotación inventario',
                        'value' => $safe($inventory * 365, $cogs),
                        'format' => 'days',
                        'benchmark' => 'Menos = más eficiente',
                        'status' => 'neutral',
                    ],
                    [
                        'name' => 'Ciclo operativo',
                        'formula' => 'Días cartera + Días inventario',
                        'value' => $safe($receivables * 365, $sales) + $safe($inventory * 365, $cogs),
                        'format' => 'days',
                        'benchmark' => 'Tiempo de recuperación de caja',
                        'status' => 'neutral',
                    ],
                ],
            ],
        ];
    }

    protected function sumPrefix(array $accounts, string $prefix): float
    {
        $total = 0.0;
        foreach ($accounts as $a) {
            if (str_starts_with($a['code'], $prefix)) {
                $total += $a['balance'];
            }
        }
        return $total;
    }

    /** good ≥ excellent · warning ≥ acceptable · bad si menor. */
    protected function statusFromThresholds(float $v, float $excellent, float $acceptable): string
    {
        if ($v >= $excellent) return 'good';
        if ($v >= $acceptable) return 'warning';
        return 'bad';
    }

    /** Para indicadores donde "menos es mejor" (ej. endeudamiento). */
    protected function statusFromThresholdsInverse(float $v, float $excellent, float $acceptable): string
    {
        if ($v <= $excellent) return 'good';
        if ($v <= $acceptable) return 'warning';
        return 'bad';
    }
}
