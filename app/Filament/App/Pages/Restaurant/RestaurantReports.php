<?php

namespace App\Filament\App\Pages\Restaurant;

use App\Models\Restaurant\Order;
use App\Models\Restaurant\OrderItem;
use App\Support\AccountantContext;
use App\Support\CurrentCompany;
use App\Support\ModuleGate;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reportes del módulo restaurante:
 *  - Propinas por mesero
 *  - Items más vendidos
 *  - Tiempos promedio de preparación (received → preparing → ready → served)
 *
 * Todas las consultas filtran por rango de fechas (default últimos 30 días)
 * y por la empresa actual via global scope BelongsToCompany.
 */
class RestaurantReports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reportes restaurante';

    protected static ?string $navigationGroup = 'Restaurante';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Reportes';

    protected static ?string $slug = 'restaurant-reports';

    protected static string $view = 'filament.app.pages.restaurant.restaurant-reports';

    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public int $topItemsLimit = 15;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('restaurant')) return false;
        if (! AccountantContext::ready()) return false;
        if (! \App\Support\RestaurantSettings::isEnabled('reports')) return false;
        return (bool) Auth::user()?->can('restaurant.reports.view');
    }

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(30)->startOfDay()->format('Y-m-d');
        $this->dateTo = now()->endOfDay()->format('Y-m-d');
    }

    protected function rangeCarbon(): array
    {
        $from = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : now()->endOfDay();
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    protected function companyId(): ?int
    {
        return app(CurrentCompany::class)->id() ?? Auth::user()?->company_id;
    }

    public function setPreset(string $preset): void
    {
        $now = now();
        match ($preset) {
            'today' => [$this->dateFrom, $this->dateTo] = [$now->copy()->startOfDay()->format('Y-m-d'), $now->copy()->endOfDay()->format('Y-m-d')],
            'yesterday' => [$this->dateFrom, $this->dateTo] = [$now->copy()->subDay()->startOfDay()->format('Y-m-d'), $now->copy()->subDay()->endOfDay()->format('Y-m-d')],
            'week' => [$this->dateFrom, $this->dateTo] = [$now->copy()->startOfWeek()->format('Y-m-d'), $now->copy()->endOfWeek()->format('Y-m-d')],
            'month' => [$this->dateFrom, $this->dateTo] = [$now->copy()->startOfMonth()->format('Y-m-d'), $now->copy()->endOfMonth()->format('Y-m-d')],
            'last30' => [$this->dateFrom, $this->dateTo] = [$now->copy()->subDays(30)->format('Y-m-d'), $now->copy()->format('Y-m-d')],
            default => null,
        };
    }

    /**
     * Propinas y ventas por mesero. Solo órdenes cerradas (no canceladas).
     */
    public function getTipsReportProperty()
    {
        [$from, $to] = $this->rangeCarbon();
        $companyId = $this->companyId();

        return DB::table('restaurant_orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.server_user_id')
            ->select([
                'u.id as user_id',
                'u.name as server_name',
                DB::raw('count(*) as orders_count'),
                DB::raw('sum(o.subtotal) as net_sales'),
                DB::raw('sum(o.tip_amount) as tips_total'),
                DB::raw('sum(o.total) as gross_total'),
            ])
            ->where('o.company_id', $companyId)
            ->where('o.status', Order::STATUS_CLOSED)
            ->whereBetween('o.closed_at', [$from, $to])
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('tips_total')
            ->get()
            ->map(function ($row) {
                $tip = (float) $row->tips_total;
                $sales = (float) $row->net_sales;
                $row->tip_pct = $sales > 0 ? round($tip / $sales * 100, 1) : 0;
                return $row;
            });
    }

    /**
     * Top items vendidos (no cancelados) en el rango. Agrupa por descripción
     * porque el mismo producto puede tener modificadores distintos pero el
     * nombre base es la mejor agrupación de negocio.
     */
    public function getTopItemsProperty()
    {
        [$from, $to] = $this->rangeCarbon();
        $companyId = $this->companyId();

        return DB::table('restaurant_order_items as oi')
            ->join('restaurant_orders as o', 'o.id', '=', 'oi.restaurant_order_id')
            ->select([
                'oi.description',
                DB::raw('count(*) as line_count'),
                DB::raw('sum(oi.quantity) as units_sold'),
                DB::raw('sum(oi.subtotal + oi.tax_amount) as revenue'),
            ])
            ->where('o.company_id', $companyId)
            ->where('oi.kitchen_status', '!=', OrderItem::KS_CANCELLED)
            ->whereIn('o.status', [Order::STATUS_CLOSED, Order::STATUS_BILLING, Order::STATUS_SERVED, Order::STATUS_IN_KITCHEN])
            ->whereBetween('o.opened_at', [$from, $to])
            ->groupBy('oi.description')
            ->orderByDesc('units_sold')
            ->limit($this->topItemsLimit)
            ->get();
    }

    /**
     * Tiempos promedio (en minutos) por etapa: enviado→preparando→listo→servido.
     * Solo items con todos los timestamps poblados.
     */
    public function getPrepTimesProperty(): array
    {
        [$from, $to] = $this->rangeCarbon();

        $companyId = $this->companyId();

        $row = DB::table('restaurant_order_items as oi')
            ->join('restaurant_orders as o', 'o.id', '=', 'oi.restaurant_order_id')
            ->where('o.company_id', $companyId)
            ->whereBetween('o.opened_at', [$from, $to])
            ->where('oi.kitchen_status', '!=', OrderItem::KS_CANCELLED)
            ->whereNotNull('oi.sent_to_kitchen_at')
            ->selectRaw("
                count(*) as items_total,
                avg(extract(epoch from (oi.preparing_at - oi.sent_to_kitchen_at)) / 60.0) filter (where oi.preparing_at is not null) as avg_sent_to_preparing,
                avg(extract(epoch from (oi.ready_at - oi.preparing_at)) / 60.0) filter (where oi.preparing_at is not null and oi.ready_at is not null) as avg_preparing_to_ready,
                avg(extract(epoch from (oi.served_at - oi.ready_at)) / 60.0) filter (where oi.ready_at is not null and oi.served_at is not null) as avg_ready_to_served,
                avg(extract(epoch from (oi.served_at - oi.sent_to_kitchen_at)) / 60.0) filter (where oi.served_at is not null) as avg_total
            ")
            ->first();

        return [
            'items_total' => (int) ($row->items_total ?? 0),
            'avg_sent_to_preparing' => round((float) ($row->avg_sent_to_preparing ?? 0), 1),
            'avg_preparing_to_ready' => round((float) ($row->avg_preparing_to_ready ?? 0), 1),
            'avg_ready_to_served' => round((float) ($row->avg_ready_to_served ?? 0), 1),
            'avg_total' => round((float) ($row->avg_total ?? 0), 1),
        ];
    }

    /**
     * Totales generales del rango: ventas, propinas, # órdenes cerradas.
     */
    public function getSummaryProperty(): array
    {
        [$from, $to] = $this->rangeCarbon();

        $companyId = $this->companyId();

        $row = DB::table('restaurant_orders')
            ->where('company_id', $companyId)
            ->where('status', Order::STATUS_CLOSED)
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('
                count(*) as orders_closed,
                coalesce(sum(subtotal), 0) as net_sales,
                coalesce(sum(tip_amount), 0) as tips_total,
                coalesce(sum(total), 0) as gross_total
            ')
            ->first();

        return [
            'orders_closed' => (int) ($row->orders_closed ?? 0),
            'net_sales' => (float) ($row->net_sales ?? 0),
            'tips_total' => (float) ($row->tips_total ?? 0),
            'gross_total' => (float) ($row->gross_total ?? 0),
        ];
    }
}
