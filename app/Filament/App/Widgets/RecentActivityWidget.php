<?php

namespace App\Filament\App\Widgets;

use App\Support\CurrentCompany;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Tabla de las últimas transacciones de la empresa (ventas + compras).
 * Muestra solo lo que el usuario tiene permiso de ver.
 */
class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.recent-activity';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('sales.view') || $user->can('purchases.view'));
    }

    public function getViewData(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
        if (! $companyId) {
            return ['activities' => collect()];
        }

        $user = auth()->user();
        $queries = [];

        if ($user?->can('sales.view')) {
            $queries[] = DB::table('sale_invoices')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'cancelled')
                ->selectRaw("
                    'sale' as kind,
                    id, prefix, number, date, total, payment_status,
                    coalesce(created_at, date) as sort_at
                ")
                ->orderByDesc('created_at')
                ->limit(20);
        }

        if ($user?->can('purchases.view')) {
            $queries[] = DB::table('purchase_invoices')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'cancelled')
                ->selectRaw("
                    'purchase' as kind,
                    id, prefix, number, date, total, payment_status,
                    coalesce(created_at, date) as sort_at
                ")
                ->orderByDesc('created_at')
                ->limit(20);
        }

        if (empty($queries)) {
            return ['activities' => collect()];
        }

        $base = array_shift($queries);
        foreach ($queries as $query) {
            $base->unionAll($query);
        }

        $activities = $base->orderByDesc('sort_at')->limit(20)->get();

        return ['activities' => $activities];
    }
}
