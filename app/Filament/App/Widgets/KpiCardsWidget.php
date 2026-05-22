<?php

namespace App\Filament\App\Widgets;

use App\Models\SaleInvoice;
use App\Support\CurrentCompany;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * KPIs de ventas e inventario. Cada card se muestra solo si el usuario
 * tiene el permiso correspondiente.
 */
class KpiCardsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('sales.view') || $user->can('inventory.view'));
    }

    protected function getStats(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
        if (! $companyId) {
            return [];
        }

        $user = auth()->user();
        $stats = [];

        if ($user?->can('sales.view')) {
            $stats = array_merge($stats, $this->salesStats($companyId));
        }

        if ($user?->can('inventory.view')) {
            $stats[] = $this->stockStat($companyId);
        }

        return $stats;
    }

    /**
     * @return array<int,Stat>
     */
    protected function salesStats(int $companyId): array
    {
        $today = now()->startOfDay()->toDateString();
        $tomorrow = now()->endOfDay()->toDateString();
        $yesterday = now()->subDay()->startOfDay()->toDateString();
        $yesterdayEnd = now()->subDay()->endOfDay()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $salesToday = (float) SaleInvoice::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$today, $tomorrow])
            ->sum('total');

        $salesYesterday = (float) SaleInvoice::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$yesterday, $yesterdayEnd])
            ->sum('total');

        $salesDelta = $salesYesterday > 0
            ? round(($salesToday - $salesYesterday) / $salesYesterday * 100, 1)
            : ($salesToday > 0 ? 100 : 0);

        $salesMonth = (float) SaleInvoice::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', $monthStart)
            ->sum('total');

        $invoicesMonth = (int) SaleInvoice::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', $monthStart)
            ->count();

        $receivables = (float) DB::table('sale_invoices')
            ->where('company_id', $companyId)
            ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido'])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('coalesce(sum(total - coalesce(paid_amount, 0)), 0) as balance')
            ->value('balance');

        return [
            Stat::make('Ventas hoy', '$'.number_format($salesToday, 0, ',', '.'))
                ->description($salesDelta >= 0 ? "↑ {$salesDelta}% vs ayer" : abs($salesDelta).'% vs ayer')
                ->descriptionIcon($salesDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($salesDelta >= 0 ? 'success' : 'danger')
                ->chart([$salesYesterday, $salesToday]),

            Stat::make('Ventas del mes', '$'.number_format($salesMonth, 0, ',', '.'))
                ->description("{$invoicesMonth} facturas posteadas")
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info'),

            Stat::make('Por cobrar (cartera)', '$'.number_format($receivables, 0, ',', '.'))
                ->description($receivables > 0 ? 'Saldo pendiente' : 'Sin cartera ✓')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color($receivables > 0 ? 'warning' : 'success'),
        ];
    }

    protected function stockStat(int $companyId): Stat
    {
        $lowStockCount = $this->lowStockCount($companyId);

        return Stat::make('Alertas de stock', $lowStockCount)
            ->description($lowStockCount > 0 ? 'Productos en mínimo o agotados' : 'Inventario en regla ✓')
            ->descriptionIcon('heroicon-m-archive-box')
            ->color($lowStockCount > 0 ? 'danger' : 'success')
            ->url($lowStockCount > 0 ? '/app/inventory' : null);
    }

    /**
     * Cuenta product_locations con stock <= min_stock vía LATERAL join al
     * último inventory_movement.
     */
    protected function lowStockCount(int $companyId): int
    {
        try {
            return (int) DB::select("
                select count(*) as c
                from product_locations pl
                join products p on p.id = pl.product_id
                left join lateral (
                    select balance_quantity_after as stock
                    from inventory_movements
                    where product_id = pl.product_id and location_id = pl.location_id
                    order by created_at desc, id desc
                    limit 1
                ) latest on true
                where p.company_id = ?
                  and p.active = true
                  and p.track_inventory = true
                  and pl.active = true
                  and pl.min_stock is not null
                  and pl.min_stock > 0
                  and coalesce(latest.stock, 0) <= pl.min_stock
            ", [$companyId])[0]->c ?? 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
