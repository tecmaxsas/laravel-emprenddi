<?php

namespace App\Filament\App\Widgets;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollSettlement;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Table;
use App\Support\CurrentCompany;
use App\Support\ModuleGate;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard unificado del escritorio: reúne todos los indicadores del
 * negocio en un solo widget visual. Cada sección se calcula y se muestra
 * solo si el usuario tiene el permiso correspondiente.
 */
class DashboardOverviewWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.dashboard-overview';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user();
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $companyId = app(CurrentCompany::class)->id() ?? $user?->company_id;

        $can = fn (string $permission) => (bool) $user?->can($permission);

        $data = [
            'userName' => $user?->name ?? 'Usuario',
            'roleLabel' => $this->roleLabel($user),
            'canSales' => $can('sales.view'),
            'canInventory' => $can('inventory.view'),
            'canPurchases' => $can('purchases.view'),
            'canPayroll' => $can('payroll.employees.view') || $can('payroll.periods.view'),
            'canRestaurant' => ModuleGate::active('restaurant') && $can('restaurant.use'),
            'sales' => null,
            'inventory' => null,
            'purchases' => null,
            'payroll' => null,
            'restaurant' => null,
            'activity' => collect(),
            'salesSeries' => [],
        ];

        if (! $companyId) {
            return $data;
        }

        if ($data['canSales']) {
            $data['sales'] = $this->salesData($companyId);
            $data['salesSeries'] = $this->salesSeries($companyId);
        }
        if ($data['canInventory']) {
            $data['inventory'] = ['low_stock' => $this->lowStockCount($companyId)];
        }
        if ($data['canPurchases']) {
            $data['purchases'] = $this->purchasesData($companyId);
        }
        if ($data['canPayroll']) {
            $data['payroll'] = $this->payrollData($companyId, $user);
        }
        if ($data['canRestaurant']) {
            $data['restaurant'] = $this->restaurantData($companyId);
        }
        if ($data['canSales'] || $data['canPurchases']) {
            $data['activity'] = $this->recentActivity($companyId, $data['canSales'], $data['canPurchases']);
        }

        // Preferencias del usuario: lista ordenada de secciones visibles.
        // El blade recorre $visibleSections en orden y renderiza cada una
        // solo si está en la lista. Respeta permisos (availableFor) + la
        // configuración personal (ocultas/orden) de PersonalizarEscritorio.
        $data['visibleSections'] = \App\Models\DashboardPreference::visibleSectionsFor($user);

        return $data;
    }

    protected function roleLabel($user): string
    {
        $role = $user?->roles?->first()?->name;

        return match ($role) {
            'admin' => 'Administrador',
            'manager' => 'Gerente',
            'accountant', 'accountant_external' => 'Contador',
            'cashier' => 'Cajero',
            'seller' => 'Vendedor',
            default => 'Equipo',
        };
    }

    protected function salesData(int $companyId): array
    {
        $today = now()->startOfDay()->toDateString();
        $todayEnd = now()->endOfDay()->toDateString();
        $yesterday = now()->subDay()->startOfDay()->toDateString();
        $yesterdayEnd = now()->subDay()->endOfDay()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $base = fn () => DB::table('sale_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled');

        $salesToday = (float) $base()->whereBetween('date', [$today, $todayEnd])->sum('total');
        $salesYesterday = (float) $base()->whereBetween('date', [$yesterday, $yesterdayEnd])->sum('total');
        $salesMonth = (float) $base()->where('date', '>=', $monthStart)->sum('total');
        $invoicesMonth = (int) $base()->where('date', '>=', $monthStart)->count();

        $receivables = (float) DB::table('sale_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido'])
            ->selectRaw('coalesce(sum(total - coalesce(paid_amount, 0)), 0) as b')
            ->value('b');

        $delta = $salesYesterday > 0
            ? round(($salesToday - $salesYesterday) / $salesYesterday * 100, 1)
            : ($salesToday > 0 ? 100.0 : 0.0);

        return [
            'today' => $salesToday,
            'yesterday' => $salesYesterday,
            'month' => $salesMonth,
            'invoices_month' => $invoicesMonth,
            'receivables' => $receivables,
            'delta' => $delta,
        ];
    }

    /**
     * Serie de ventas de los últimos 14 días para la gráfica de barras.
     *
     * @return array<int,array{label:string,dow:string,total:float}>
     */
    protected function salesSeries(int $companyId): array
    {
        $from = now()->subDays(13)->toDateString();

        $raw = DB::table('sale_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->where('date', '>=', $from)
            ->groupBy('date')
            ->selectRaw('date, sum(total) as t')
            ->pluck('t', 'date');

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $series[] = [
                'label' => $day->format('d/m'),
                'dow' => mb_substr($day->locale('es')->isoFormat('ddd'), 0, 3),
                'total' => (float) ($raw[$day->toDateString()] ?? 0),
            ];
        }

        return $series;
    }

    protected function purchasesData(int $companyId): array
    {
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
            ->selectRaw('coalesce(sum(total - coalesce(paid_amount, 0)), 0) as b')
            ->value('b');

        $dueSoon = (float) DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['pendiente', 'parcial', 'vencido'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->selectRaw('coalesce(sum(total - coalesce(paid_amount, 0)), 0) as b')
            ->value('b');

        return [
            'month' => $purchasesMonth,
            'payables' => $payables,
            'due_soon' => $dueSoon,
        ];
    }

    protected function payrollData(int $companyId, $user): array
    {
        $employees = $user?->can('payroll.employees.view')
            ? Employee::query()->where('company_id', $companyId)->where('status', Employee::STATUS_ACTIVE)->count()
            : null;

        $lastNet = null;
        $lastPeriodName = null;
        $pendingSettlements = null;

        if ($user?->can('payroll.periods.view')) {
            $lastPeriod = PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->whereIn('status', [PayrollPeriod::STATUS_LIQUIDATED, PayrollPeriod::STATUS_POSTED])
                ->orderByDesc('start_date')
                ->first();
            $lastNet = $lastPeriod ? (float) $lastPeriod->slips()->sum('net_pay') : 0.0;
            $lastPeriodName = $lastPeriod?->name;
            $pendingSettlements = PayrollSettlement::query()
                ->where('company_id', $companyId)
                ->where('status', PayrollSettlement::STATUS_DRAFT)
                ->count();
        }

        return [
            'employees' => $employees,
            'last_net' => $lastNet,
            'last_period' => $lastPeriodName,
            'pending_settlements' => $pendingSettlements,
        ];
    }

    protected function restaurantData(int $companyId): array
    {
        $activeStatuses = [
            Order::STATUS_OPEN,
            Order::STATUS_IN_KITCHEN,
            Order::STATUS_SERVED,
            Order::STATUS_BILLING,
        ];

        $occupied = Table::query()->where('company_id', $companyId)->where('active', true)->where('status', 'occupied')->count();
        $totalTables = Table::query()->where('company_id', $companyId)->where('active', true)->count();
        $openOrders = Order::query()->where('company_id', $companyId)->whereIn('status', $activeStatuses)->count();
        $deliveries = Order::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $activeStatuses)
            ->where('is_delivery', true)
            ->whereRaw("coalesce(delivery_metadata->>'delivery_status', 'preparing') != 'delivered'")
            ->count();
        $todaySales = (float) Order::query()
            ->where('company_id', $companyId)
            ->where('status', Order::STATUS_CLOSED)
            ->whereBetween('closed_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('total');

        return [
            'occupied' => $occupied,
            'total_tables' => $totalTables,
            'open_orders' => $openOrders,
            'deliveries' => $deliveries,
            'today_sales' => $todaySales,
        ];
    }

    protected function recentActivity(int $companyId, bool $canSales, bool $canPurchases)
    {
        $queries = [];

        if ($canSales) {
            $queries[] = DB::table('sale_invoices')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'cancelled')
                ->selectRaw("'sale' as kind, id, prefix, number, date, total, payment_status, coalesce(created_at, date) as sort_at")
                ->orderByDesc('created_at')
                ->limit(12);
        }
        if ($canPurchases) {
            $queries[] = DB::table('purchase_invoices')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'cancelled')
                ->selectRaw("'purchase' as kind, id, prefix, number, date, total, payment_status, coalesce(created_at, date) as sort_at")
                ->orderByDesc('created_at')
                ->limit(12);
        }
        if (empty($queries)) {
            return collect();
        }

        $base = array_shift($queries);
        foreach ($queries as $query) {
            $base->unionAll($query);
        }

        return $base->orderByDesc('sort_at')->limit(12)->get();
    }

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
