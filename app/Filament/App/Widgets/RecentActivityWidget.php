<?php

namespace App\Filament\App\Widgets;

use App\Support\CurrentCompany;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Tabla de las ultimas N transacciones de la empresa: ventas, compras,
 * gastos. Da una vista rapida de "que paso hoy" en el negocio.
 */
class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.recent-activity';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user();
    }

    public function getViewData(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;
        if (! $companyId) return ['activities' => collect()];

        // UNION ALL de ventas + compras + gastos. Postgres ordena al final.
        $sales = DB::table('sale_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->selectRaw("
                'sale' as kind,
                id,
                prefix,
                number,
                date,
                total,
                payment_status,
                coalesce(created_at, date) as sort_at
            ")
            ->orderByDesc('created_at')
            ->limit(15);

        $purchases = DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('status', '!=', 'cancelled')
            ->selectRaw("
                'purchase' as kind,
                id,
                prefix,
                number,
                date,
                total,
                payment_status,
                coalesce(created_at, date) as sort_at
            ")
            ->orderByDesc('created_at')
            ->limit(15);

        $activities = $sales->unionAll($purchases)
            ->orderByDesc('sort_at')
            ->limit(20)
            ->get();

        return ['activities' => $activities];
    }
}
