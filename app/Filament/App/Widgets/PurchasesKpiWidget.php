<?php

namespace App\Filament\App\Widgets;

use App\Support\CurrentCompany;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * KPIs de compras y cuentas por pagar. Visible solo con permiso
 * 'purchases.view'.
 */
class PurchasesKpiWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('purchases.view');
    }

    protected function getStats(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
        if (! $companyId) {
            return [];
        }

        $monthStart = now()->startOfMonth()->toDateString();

        $purchasesMonth = (float) DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', $monthStart)
            ->sum('total');

        $payables = (float) DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido'])
            ->selectRaw('coalesce(sum(total - coalesce(paid_amount, 0)), 0) as balance')
            ->value('balance');

        $dueSoon = (float) DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->selectRaw('coalesce(sum(total - coalesce(paid_amount, 0)), 0) as balance')
            ->value('balance');

        return [
            Stat::make('Compras del mes', '$'.number_format($purchasesMonth, 0, ',', '.'))
                ->description('Facturas y documentos soporte')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),

            Stat::make('Cuentas por pagar', '$'.number_format($payables, 0, ',', '.'))
                ->description($payables > 0 ? 'Saldo pendiente a proveedores' : 'Sin deuda ✓')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($payables > 0 ? 'warning' : 'success'),

            Stat::make('Por pagar esta semana', '$'.number_format($dueSoon, 0, ',', '.'))
                ->description($dueSoon > 0 ? 'Vence en los próximos 7 días' : 'Sin vencimientos cercanos ✓')
                ->descriptionIcon('heroicon-m-clock')
                ->color($dueSoon > 0 ? 'danger' : 'success'),
        ];
    }
}
