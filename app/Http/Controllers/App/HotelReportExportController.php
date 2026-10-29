<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Hotel\Stay;
use App\Services\Hotel\HotelReports;
use App\Services\Reports\TabularReportExporter;
use App\Support\ModuleGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los Excel de los reportes de hotel.
 *
 * Las cifras NO se recalculan aquí: salen de HotelReports, el mismo servicio
 * que alimenta la pantalla. Un exportador que arma su propia consulta es un
 * exportador que algún día entrega un total distinto al que el usuario acaba
 * de ver, y no hay manera de saber cuál de los dos está bien.
 */
class HotelReportExportController extends Controller
{
    public function __construct(
        protected TabularReportExporter $tabular,
        protected HotelReports $reportes,
    ) {}

    /** Ocupación, ADR y RevPAR día por día. */
    public function occupancy(Request $request): StreamedResponse
    {
        $this->autorizar($request);

        $filtros = $this->filtros($request);
        $datos = $this->reportes->ocupacion($filtros);

        $filas = $datos['filas']->map(fn (array $f) => [
            $f['dia'],
            $f['disponibles'],
            $f['vendidas'],
            $f['ocupacion'],
            $f['alojamiento'],
            $f['adr'],
            $f['revpar'],
            $f['consumos'],
            $f['total'],
        ]);

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Ocupación e ingresos del hotel',
                subtitle: "Período: {$filtros['from']} a {$filtros['to']}",
                companyName: $this->nombreDeLaEmpresa(),
                headers: [
                    'Día', 'Habitaciones', 'Noches vendidas', 'Ocupación %',
                    'Alojamiento', 'ADR', 'RevPAR', 'Consumos', 'Total',
                ],
                rows: $filas,
                columnTypes: [
                    'string', 'number', 'number', 'number',
                    'number', 'number', 'number', 'number', 'number',
                ],
            ),
            "hotel-ocupacion-{$filtros['from']}-a-{$filtros['to']}.xlsx",
            $this->cabecerasXlsx(),
        );
    }

    /** El detalle: una fila por estadía. */
    public function revenue(Request $request): StreamedResponse
    {
        $this->autorizar($request);

        $filtros = $this->filtros($request);

        $filas = $this->reportes->estadias($filtros)->map(fn (Stay $e) => [
            $e->fullNumber(),
            $e->room?->number ?? '',
            $e->roomType?->name ?? '',
            $e->nombreDelHuesped(),
            $e->primaryGuest?->documento() ?? '',
            $e->check_in_at?->format('Y-m-d H:i') ?? '',
            $e->check_out_at?->format('Y-m-d H:i') ?? '',
            (int) $e->nights,
            (float) $e->rate_per_night,
            (float) $e->room_total,
            (float) $e->consumption_total,
            (float) $e->discount_total,
            (float) $e->tax_total,
            (float) $e->total,
            (float) $e->paid_total,
            Stay::STATUSES[$e->status] ?? $e->status,
            $e->invoice?->fullNumber() ?? '',
        ]);

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Estadías del hotel',
                subtitle: "Entradas entre {$filtros['from']} y {$filtros['to']}",
                companyName: $this->nombreDeLaEmpresa(),
                headers: [
                    'Folio', 'Hab.', 'Tipo', 'Huésped', 'Documento',
                    'Entrada', 'Salida', 'Noches', 'Tarifa',
                    'Alojamiento', 'Consumos', 'Rebajas', 'IVA', 'Total', 'Pagado',
                    'Estado', 'Factura',
                ],
                rows: $filas,
                columnTypes: [
                    'string', 'string', 'string', 'string', 'string',
                    'string', 'string', 'number', 'number',
                    'number', 'number', 'number', 'number', 'number', 'number',
                    'string', 'string',
                ],
            ),
            "hotel-estadias-{$filtros['from']}-a-{$filtros['to']}.xlsx",
            $this->cabecerasXlsx(),
        );
    }

    /** El registro hotelero: quiénes durmieron y con qué datos. */
    public function guests(Request $request): StreamedResponse
    {
        $this->autorizar($request);

        $filtros = $this->filtros($request);
        $filtros['solo_extranjeros'] = (bool) $request->query('solo_extranjeros');

        $filas = $this->reportes->huespedes($filtros)->map(fn (array $f) => [
            $f['folio'],
            $f['habitacion'],
            $f['titular'] ? 'Titular' : 'Acompañante',
            $f['nombre'],
            $f['documento'],
            $f['nacimiento']?->format('Y-m-d') ?? '',
            $f['nacionalidad'] ?? '',
            $f['extranjero'] ? 'Sí' : 'No',
            $f['entrada']?->format('Y-m-d H:i') ?? '',
            $f['salida'] instanceof \Illuminate\Support\Carbon ? $f['salida']->format('Y-m-d') : '',
            $f['noches'],
            $f['procedencia'] ?? '',
            $f['destino'] ?? '',
            $f['motivo'] ?? '',
            $f['telefono'] ?? '',
        ]);

        $sufijo = $filtros['solo_extranjeros'] ? '-extranjeros' : '';

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Huéspedes alojados',
                subtitle: "Entradas entre {$filtros['from']} y {$filtros['to']}"
                    .($filtros['solo_extranjeros'] ? ' · solo extranjeros' : ''),
                companyName: $this->nombreDeLaEmpresa(),
                headers: [
                    'Folio', 'Hab.', 'Rol', 'Nombre', 'Documento', 'Nacimiento',
                    'Nacionalidad', 'Extranjero', 'Entrada', 'Salida', 'Noches',
                    'Procedencia', 'Destino', 'Motivo del viaje', 'Teléfono',
                ],
                rows: $filas,
                columnTypes: [
                    'string', 'string', 'string', 'string', 'string', 'string',
                    'string', 'string', 'string', 'string', 'number',
                    'string', 'string', 'string', 'string',
                ],
            ),
            "hotel-huespedes{$sufijo}-{$filtros['from']}-a-{$filtros['to']}.xlsx",
            $this->cabecerasXlsx(),
        );
    }

    protected function autorizar(Request $request): void
    {
        abort_unless(ModuleGate::active('hotel'), 403);
        abort_unless($request->user()?->can('hotel.reports'), 403);
    }

    /** @return array{from: string, to: string, hotel_id: int|null} */
    protected function filtros(Request $request): array
    {
        return [
            'from' => $this->fecha($request->query('from'), now()->startOfMonth()),
            'to' => $this->fecha($request->query('to'), now()->endOfMonth()),
            'hotel_id' => (int) $request->query('hotel_id') ?: null,
        ];
    }

    protected function fecha(?string $valor, \Illuminate\Support\Carbon $porDefecto): string
    {
        if (! $valor) {
            return $porDefecto->toDateString();
        }

        try {
            return \Illuminate\Support\Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return $porDefecto->toDateString();
        }
    }

    protected function nombreDeLaEmpresa(): string
    {
        return Auth::user()?->company?->business_name ?? Auth::user()?->company?->name ?? '';
    }

    protected function cabecerasXlsx(): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
