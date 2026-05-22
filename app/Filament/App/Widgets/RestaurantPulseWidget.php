<?php

namespace App\Filament\App\Widgets;

use App\Models\Restaurant\Order;
use App\Models\Restaurant\Table;
use App\Support\CurrentCompany;
use App\Support\ModuleGate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * KPIs en tiempo real del modulo restaurante.
 * Solo se muestra si la empresa tiene 'restaurant' activo.
 */
class RestaurantPulseWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()
            && ModuleGate::active('restaurant')
            && auth()->user()->can('restaurant.use');
    }

    protected function getStats(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
        if (! $companyId) return [];

        $activeStatuses = [
            Order::STATUS_OPEN,
            Order::STATUS_IN_KITCHEN,
            Order::STATUS_SERVED,
            Order::STATUS_BILLING,
        ];

        // Mesas ocupadas
        $occupiedTables = Table::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->where('status', 'occupied')
            ->count();

        $totalTables = Table::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->count();

        // Ordenes abiertas (todas: comer aqui + takeaway + delivery)
        $openOrders = Order::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $activeStatuses)
            ->count();

        // Deliveries en curso (no entregados)
        $activeDeliveries = Order::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $activeStatuses)
            ->where('is_delivery', true)
            ->whereRaw("coalesce(delivery_metadata->>'delivery_status', 'preparing') != 'delivered'")
            ->count();

        // Ventas del dia de restaurante (ordenes cerradas hoy)
        $today = now()->startOfDay();
        $endToday = now()->endOfDay();
        $todaySales = (float) Order::query()
            ->where('company_id', $companyId)
            ->where('status', Order::STATUS_CLOSED)
            ->whereBetween('closed_at', [$today, $endToday])
            ->sum('total');

        return [
            Stat::make('Mesas ocupadas', "{$occupiedTables} / {$totalTables}")
                ->description($totalTables > 0
                    ? round($occupiedTables / $totalTables * 100).'% de ocupación'
                    : 'Sin mesas configuradas')
                ->descriptionIcon('heroicon-m-table-cells')
                ->color($occupiedTables / max($totalTables, 1) > 0.7 ? 'warning' : 'success'),

            Stat::make('Órdenes abiertas', (string) $openOrders)
                ->description($openOrders > 0 ? 'En proceso ahora mismo' : 'Sin órdenes activas')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color('info')
                ->url('/app/restaurant-pos'),

            Stat::make('Domicilios activos', (string) $activeDeliveries)
                ->description($activeDeliveries > 0 ? 'Sin entregar todavía' : 'Sin pendientes')
                ->descriptionIcon('heroicon-m-truck')
                ->color($activeDeliveries > 0 ? 'warning' : 'success')
                ->url('/app/delivery-dispatch'),

            Stat::make('Ventas hoy (restaurante)', '$'.number_format($todaySales, 0, ',', '.'))
                ->description('Solo órdenes cerradas hoy')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
