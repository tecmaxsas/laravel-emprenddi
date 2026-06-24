<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Parking\ParkingSession;
use App\Services\Reports\TabularReportExporter;
use App\Support\ModuleGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports XLSX de los reportes de parqueadero. Reusa TabularReportExporter
 * (OpenSpout) que ya esta en el proyecto.
 */
class ParkingReportExportController extends Controller
{
    public function __construct(protected TabularReportExporter $tabular) {}

    public function sessions(Request $request): StreamedResponse
    {
        abort_unless(ModuleGate::active('parking'), 403);
        abort_unless($request->user()?->can('parking.reports'), 403);

        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));
        $lotId = (int) $request->query('parking_lot_id') ?: null;
        $status = (string) $request->query('status') ?: null;

        $rows = ParkingSession::query()
            ->whereDate('entry_at', '>=', $from)
            ->whereDate('entry_at', '<=', $to)
            ->when($lotId, fn (Builder $q) => $q->where('parking_lot_id', $lotId))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->with(['parkingLot:id,name', 'vehicleType:id,name', 'saleInvoice:id,prefix,number'])
            ->orderByDesc('entry_at')
            ->get()
            ->map(fn (ParkingSession $s) => [
                '#'.str_pad((string) $s->id, 6, '0', STR_PAD_LEFT),
                $s->plate,
                $s->parkingLot?->name ?: '',
                $s->vehicleType?->name ?: '',
                $s->entry_at?->format('Y-m-d H:i') ?: '',
                $s->exit_at?->format('Y-m-d H:i') ?: '',
                (int) ($s->total_minutes ?? 0),
                (float) $s->amount,
                ParkingSession::STATUSES[$s->status] ?? $s->status,
                $s->saleInvoice?->fullNumber() ?: '',
            ]);

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Sesiones de parqueadero',
                subtitle: "Período: {$from} a {$to}",
                companyName: $this->companyName(),
                headers: ['Ticket', 'Placa', 'Parqueadero', 'Vehículo', 'Entrada', 'Salida', 'Minutos', 'Monto', 'Estado', 'Factura'],
                rows: $rows,
                columnTypes: ['string', 'string', 'string', 'string', 'string', 'string', 'number', 'number', 'string', 'string'],
            ),
            "parqueadero-sesiones-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    public function revenue(Request $request): StreamedResponse
    {
        abort_unless(ModuleGate::active('parking'), 403);
        abort_unless($request->user()?->can('parking.reports'), 403);

        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));
        $lotId = (int) $request->query('parking_lot_id') ?: null;

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
            ->get()
            ->map(fn ($r) => [
                $r->day,
                (int) $r->sessions,
                (int) $r->minutes,
                (float) $r->revenue,
                $r->sessions > 0 ? round((float) $r->revenue / (int) $r->sessions, 2) : 0,
                (int) $r->memberships,
                (int) $r->lost_tickets,
            ]);

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Ingresos de parqueadero',
                subtitle: "Período: {$from} a {$to}",
                companyName: $this->companyName(),
                headers: ['Día', 'Sesiones', 'Minutos', 'Ingresos', 'Ticket promedio', 'Mensualidades', 'Ticket perdido'],
                rows: $rows,
                columnTypes: ['string', 'number', 'number', 'number', 'number', 'number', 'number'],
            ),
            "parqueadero-ingresos-{$from}-a-{$to}.xlsx",
            $this->xlsxHeaders(),
        );
    }

    protected function parseDate(?string $value): string
    {
        if (! $value) return now()->startOfMonth()->toDateString();
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return now()->startOfMonth()->toDateString();
        }
    }

    protected function companyName(): string
    {
        return Auth::user()?->company?->business_name ?? Auth::user()?->company?->name ?? '';
    }

    protected function xlsxHeaders(): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
