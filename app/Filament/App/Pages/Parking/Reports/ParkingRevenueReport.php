<?php

namespace App\Filament\App\Pages\Parking\Reports;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSession;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ingresos por parqueadero, agrupado por dia. Muestra cuantas sesiones, cuantos
 * minutos vendidos y total cobrado, comparable entre sedes. Es la vista mas
 * util para el dueño/gerente: "cuanto facture ayer".
 */
class ParkingRevenueReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Ingresos por día';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 81;
    protected static ?string $title = 'Ingresos del parqueadero';

    protected static string $view = 'filament.app.pages.parking.reports.revenue';

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.reports');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'parking_lot_id' => null,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),
                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->placeholder('Todos')->live()
                        ->options(fn () => ParkingLot::query()
                            ->orderBy('name')->pluck('name', 'id')->all()),
                ]),
        ])->statePath('filters');
    }

    public function getExportUrl(): string
    {
        return route('parking.reports.export.revenue', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'parking_lot_id' => $this->filters['parking_lot_id'] ?? null,
        ]));
    }

    public function getViewData(): array
    {
        $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();
        $lotId = $this->filters['parking_lot_id'] ?? null;

        $rows = ParkingSession::query()
            ->select(
                DB::raw('DATE(entry_at) as day'),
                DB::raw('COUNT(*) as sessions'),
                DB::raw('COALESCE(SUM(total_minutes), 0) as minutes'),
                DB::raw('COALESCE(SUM(amount), 0) as revenue'),
                DB::raw('SUM(CASE WHEN status = '."'lost_ticket' THEN 1 ELSE 0 END) as lost_tickets"),
                DB::raw('SUM(CASE WHEN parking_membership_id IS NOT NULL THEN 1 ELSE 0 END) as memberships'),
            )
            ->whereDate('entry_at', '>=', $from)
            ->whereDate('entry_at', '<=', $to)
            ->whereIn('status', [
                ParkingSession::STATUS_CLOSED,
                ParkingSession::STATUS_LOST_TICKET,
            ])
            ->when($lotId, fn (Builder $q) => $q->where('parking_lot_id', $lotId))
            ->groupBy(DB::raw('DATE(entry_at)'))
            ->orderByDesc(DB::raw('DATE(entry_at)'))
            ->get();

        $totals = [
            'sessions' => (int) $rows->sum('sessions'),
            'minutes' => (int) $rows->sum('minutes'),
            'revenue' => (float) $rows->sum('revenue'),
            'lost_tickets' => (int) $rows->sum('lost_tickets'),
            'memberships' => (int) $rows->sum('memberships'),
            'avg_per_session' => $rows->sum('sessions') > 0
                ? (float) $rows->sum('revenue') / max(1, (int) $rows->sum('sessions'))
                : 0,
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
        ];
    }
}
