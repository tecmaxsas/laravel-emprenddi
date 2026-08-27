<?php

namespace App\Filament\App\Pages\Reports;

use App\Services\Reports\FinancialStatementsEngine;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Estado de Resultados Integral (P&G) por periodo. Calcula el desempeño
 * economico del negocio: ingresos − costos − gastos = utilidad. Estructura
 * bajo PUC colombiano: 41/42 ingresos, 51/52/53 gastos, 61/62/7 costos,
 * 54 impuesto de renta.
 */
class IncomeStatementPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Estado de Resultados';
    protected static ?string $navigationGroup = 'Reportes contables';
    protected static ?string $title = 'Estado de Resultados Integral';
    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.app.pages.reports.income-statement';

    public ?array $filters = [];
    public array $data = [];

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        // Contable: se oculta si la empresa no tiene el modulo.
        if (! \App\Support\ModuleGate::active(\App\Support\ModuleGate::ACCOUNTING)) return false;
        return (bool) auth()->user()?->can('reports.income_statement');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ];
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Período')
                    ->columns(4)
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label('Desde')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshData()),
                        Forms\Components\DatePicker::make('to')
                            ->label('Hasta')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshData()),
                        Forms\Components\Placeholder::make('preset_hint')
                            ->label('Períodos rápidos')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div style="display:flex; gap:6px; flex-wrap:wrap;">'
                                .'<button type="button" wire:click="presetMonth" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Mes actual</button>'
                                .'<button type="button" wire:click="presetQuarter" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Trimestre</button>'
                                .'<button type="button" wire:click="presetYear" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Año</button>'
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

    public function presetQuarter(): void
    {
        $this->filters['from'] = now()->startOfQuarter()->toDateString();
        $this->filters['to'] = now()->endOfQuarter()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function presetYear(): void
    {
        $this->filters['from'] = now()->startOfYear()->toDateString();
        $this->filters['to'] = now()->endOfYear()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    protected function refreshData(): void
    {
        $companyId = auth()->user()->company_id;
        $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

        $this->data = app(FinancialStatementsEngine::class)->incomeStatement($companyId, $from, $to);
    }
}
