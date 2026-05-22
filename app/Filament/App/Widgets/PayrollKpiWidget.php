<?php

namespace App\Filament\App\Widgets;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollSettlement;
use App\Support\CurrentCompany;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * KPIs de nómina. Visible para usuarios con permisos de nómina.
 */
class PayrollKpiWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('payroll.employees.view') || $user->can('payroll.periods.view'));
    }

    protected function getStats(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
        if (! $companyId) {
            return [];
        }

        $user = auth()->user();
        $stats = [];

        if ($user?->can('payroll.employees.view')) {
            $activeEmployees = Employee::query()
                ->where('company_id', $companyId)
                ->where('status', Employee::STATUS_ACTIVE)
                ->count();

            $stats[] = Stat::make('Empleados activos', (string) $activeEmployees)
                ->description($activeEmployees > 0 ? 'En nómina' : 'Sin empleados registrados')
                ->descriptionIcon('heroicon-m-identification')
                ->color('info');
        }

        if ($user?->can('payroll.periods.view')) {
            $lastPeriod = PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->whereIn('status', [PayrollPeriod::STATUS_LIQUIDATED, PayrollPeriod::STATUS_POSTED])
                ->orderByDesc('start_date')
                ->first();

            $lastNet = $lastPeriod
                ? (float) $lastPeriod->slips()->sum('net_pay')
                : 0.0;

            $stats[] = Stat::make('Última nómina liquidada', '$'.number_format($lastNet, 0, ',', '.'))
                ->description($lastPeriod ? $lastPeriod->name : 'Sin nóminas liquidadas')
                ->descriptionIcon('heroicon-m-calculator')
                ->color('success');

            $pendingSettlements = PayrollSettlement::query()
                ->where('company_id', $companyId)
                ->where('status', PayrollSettlement::STATUS_DRAFT)
                ->count();

            $stats[] = Stat::make('Liquidaciones por pagar', (string) $pendingSettlements)
                ->description($pendingSettlements > 0 ? 'Prestaciones en borrador' : 'Sin pendientes ✓')
                ->descriptionIcon('heroicon-m-gift')
                ->color($pendingSettlements > 0 ? 'warning' : 'success');
        }

        return $stats;
    }
}
