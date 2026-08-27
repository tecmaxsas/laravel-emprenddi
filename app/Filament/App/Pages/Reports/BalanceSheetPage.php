<?php

namespace App\Filament\App\Pages\Reports;

use App\Services\Reports\FinancialStatementsEngine;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Estado de Situacion Financiera (Balance General) a una fecha de corte.
 * Fotografia del negocio: Activos = Pasivos + Patrimonio.
 *
 * Estructura bajo PUC colombiano:
 *  - Activo corriente: 11 efectivo, 12 inversiones, 13 deudores, 14 inventarios, 17 diferidos
 *  - Activo no corriente: 15 PPE, 16 intangibles, 18 otros, 19 valorizaciones
 *  - Pasivo corriente: 21-26
 *  - Pasivo no corriente: 27-28
 *  - Patrimonio: 3xx + resultado del ejercicio (calculado)
 */
class BalanceSheetPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?string $navigationLabel = 'Balance General';
    protected static ?string $navigationGroup = 'Reportes contables';
    protected static ?string $title = 'Estado de Situación Financiera';
    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.app.pages.reports.balance-sheet';

    public ?array $filters = [];
    public array $data = [];

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        // Contable: se oculta si la empresa no tiene el modulo.
        if (! \App\Support\ModuleGate::active(\App\Support\ModuleGate::ACCOUNTING)) return false;
        return (bool) auth()->user()?->can('reports.balance_sheet');
    }

    public function mount(): void
    {
        $this->filters = [
            'as_of' => now()->endOfMonth()->toDateString(),
        ];
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Fecha de corte')
                    ->columns(4)
                    ->schema([
                        Forms\Components\DatePicker::make('as_of')
                            ->label('Corte al')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->refreshData()),
                        Forms\Components\Placeholder::make('preset_hint')
                            ->label('Cortes rápidos')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div style="display:flex; gap:6px; flex-wrap:wrap;">'
                                .'<button type="button" wire:click="presetToday" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Hoy</button>'
                                .'<button type="button" wire:click="presetMonthEnd" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Fin de mes</button>'
                                .'<button type="button" wire:click="presetYearEnd" style="padding:6px 10px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; border-radius:6px; font-weight:600; cursor:pointer; font-size:12px;">Fin de año</button>'
                                .'</div>'
                            ))
                            ->columnSpan(3),
                    ]),
            ])
            ->statePath('filters');
    }

    public function presetToday(): void
    {
        $this->filters['as_of'] = now()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function presetMonthEnd(): void
    {
        $this->filters['as_of'] = now()->endOfMonth()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    public function presetYearEnd(): void
    {
        $this->filters['as_of'] = now()->endOfYear()->toDateString();
        $this->form->fill($this->filters);
        $this->refreshData();
    }

    protected function refreshData(): void
    {
        $companyId = auth()->user()->company_id;
        $asOf = $this->filters['as_of'] ?? now()->endOfMonth()->toDateString();

        $this->data = app(FinancialStatementsEngine::class)->balanceSheet($companyId, $asOf);
    }
}
