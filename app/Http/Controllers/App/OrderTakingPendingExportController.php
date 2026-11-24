<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\OrderTaking\PendingDeliveries;
use App\Services\Reports\TabularReportExporter;
use App\Support\ModuleGate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel y PDF de los informes de no entregas.
 *
 * Las cifras NO se recalculan aquí: salen de `PendingDeliveries`, el mismo
 * servicio que alimenta las dos pantallas. Un exportador con su propia
 * consulta es como se llega a que el Excel y la pantalla digan cosas
 * distintas, y entonces nadie se fía de ninguno de los dos.
 */
class OrderTakingPendingExportController extends Controller
{
    public function __construct(
        protected TabularReportExporter $tabular,
        protected PendingDeliveries $pendientes,
    ) {}

    /** Detalle línea por línea, en Excel. */
    public function pendingXlsx(Request $request): StreamedResponse
    {
        $this->autorizar($request);

        $filtros = $this->filtrosDelDetalle($request);
        $filas = $this->pendientes->filas($filtros);

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'No entregas: referencias pendientes',
                subtitle: $this->subtituloDelRango($filtros),
                companyName: $this->nombreDeLaEmpresa(),
                headers: [
                    'Pedido', 'Fecha', 'Días', 'Cliente', 'NIT', 'Código', 'Referencia',
                    'Pedido', 'Despachado', 'Pendiente', 'Precio', 'Valor pendiente',
                    'Estado', 'Motivo del cierre',
                ],
                rows: $filas->map(fn (array $f) => [
                    $f['pedido'], $f['fecha'], $f['dias'], $f['cliente'], $f['nit'],
                    $f['codigo'], $f['referencia'],
                    $f['pedido_cant'], $f['despachado'], $f['pendiente'],
                    $f['precio'], $f['valor_pendiente'],
                    $f['estado'], $f['motivo_cierre'],
                ]),
                columnTypes: [
                    'string', 'string', 'number', 'string', 'string', 'string', 'string',
                    'number', 'number', 'number', 'number', 'number',
                    'string', 'string',
                ],
            ),
            'no-entregas-'.($filtros['from'] ?? 'inicio').'-a-'.($filtros['to'] ?? 'hoy').'.xlsx',
            $this->cabecerasXlsx(),
        );
    }

    /** Detalle línea por línea, en PDF. */
    public function pendingPdf(Request $request): Response
    {
        $this->autorizar($request);
        $this->exigirDompdf();

        $filtros = $this->filtrosDelDetalle($request);

        $pdf = Pdf::loadView('order-taking.pending-pdf', [
            'filas' => $this->pendientes->filas($filtros),
            'resumen' => $this->pendientes->resumen($filtros),
            'subtitulo' => $this->subtituloDelRango($filtros),
            'company' => Auth::user()->company,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('no-entregas-'.($filtros['from'] ?? '').'.pdf');
    }

    /** Consolidado del mes por cliente, en Excel. */
    public function monthlyXlsx(Request $request): StreamedResponse
    {
        $this->autorizar($request);

        $mes = $this->mes($request);
        $consolidado = $this->pendientes->consolidadoMensual($mes, $this->filtrosDeCierre($request));

        // Una fila por referencia, repitiendo el cliente. En una hoja de
        // cálculo es lo correcto: así se puede filtrar y hacer tabla
        // dinámica. Las celdas combinadas se ven bonitas y no se dejan
        // ordenar, que es lo primero que alguien va a intentar.
        $filas = [];

        foreach ($consolidado as $cliente) {
            foreach ($cliente['lineas'] as $linea) {
                $filas[] = [
                    $cliente['cliente'],
                    $cliente['nit'],
                    $linea['codigo'],
                    $linea['referencia'],
                    $linea['pendiente'],
                    $linea['valor'],
                    implode(', ', $linea['pedidos']),
                ];
            }
        }

        return response()->streamDownload(
            $this->tabular->stream(
                title: 'Consolidado mensual de pendientes por cliente',
                subtitle: 'Mes: '.$this->etiquetaDelMes($mes),
                companyName: $this->nombreDeLaEmpresa(),
                headers: ['Cliente', 'NIT', 'Código', 'Referencia', 'Pendiente', 'Valor pendiente', 'Pedidos'],
                rows: collect($filas),
                columnTypes: ['string', 'string', 'string', 'string', 'number', 'number', 'string'],
            ),
            "consolidado-pendientes-{$mes}.xlsx",
            $this->cabecerasXlsx(),
        );
    }

    /** Consolidado del mes por cliente, en PDF. */
    public function monthlyPdf(Request $request): Response
    {
        $this->autorizar($request);
        $this->exigirDompdf();

        $mes = $this->mes($request);
        $consolidado = $this->pendientes->consolidadoMensual($mes, $this->filtrosDeCierre($request));

        $pdf = Pdf::loadView('order-taking.monthly-pending-pdf', [
            'consolidado' => $consolidado,
            'mes' => $this->etiquetaDelMes($mes),
            'company' => Auth::user()->company,
        ])->setPaper('a4');

        return $pdf->stream("consolidado-pendientes-{$mes}.pdf");
    }

    /* ------------------------------------------------------------------ */

    protected function autorizar(Request $request): void
    {
        abort_unless(ModuleGate::active('order_taking'), 403);
        abort_unless($request->user()?->can('order_taking.use'), 403);
    }

    protected function exigirDompdf(): void
    {
        abort_unless(
            class_exists(Pdf::class),
            500,
            'El paquete barryvdh/laravel-dompdf no está instalado. Ejecuta composer install en el container.',
        );
    }

    /** @return array<string, mixed> */
    protected function filtrosDelDetalle(Request $request): array
    {
        return array_merge($this->filtrosDeCierre($request), [
            'from' => $this->fecha($request->query('from')),
            'to' => $this->fecha($request->query('to')),
            'third_party_id' => (int) $request->query('third_party_id') ?: null,
            'product_id' => (int) $request->query('product_id') ?: null,
        ]);
    }

    /** @return array<string, bool> */
    protected function filtrosDeCierre(Request $request): array
    {
        $cerrados = (string) $request->query('cerrados', 'todos');

        return [
            'incluir_cerrados' => $cerrados !== 'excluir',
            'solo_cerrados' => $cerrados === 'solo',
        ];
    }

    protected function mes(Request $request): string
    {
        $mes = (string) $request->query('mes', '');

        return preg_match('/^\d{4}-\d{2}$/', $mes) ? $mes : now()->format('Y-m');
    }

    protected function etiquetaDelMes(string $mes): string
    {
        return ucfirst(
            Carbon::createFromFormat('Y-m-d', $mes.'-01')->locale('es')->isoFormat('MMMM [de] YYYY')
        );
    }

    protected function subtituloDelRango(array $filtros): string
    {
        $desde = $filtros['from'] ?? '—';
        $hasta = $filtros['to'] ?? '—';

        $sufijo = match (true) {
            ($filtros['solo_cerrados'] ?? false) => ' · solo pedidos cerrados con faltantes',
            ! ($filtros['incluir_cerrados'] ?? true) => ' · sin los pedidos cerrados',
            default => '',
        };

        return "Período: {$desde} a {$hasta}{$sufijo}";
    }

    protected function fecha(?string $valor): ?string
    {
        if (! $valor) {
            return null;
        }

        try {
            return Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nombreDeLaEmpresa(): string
    {
        return (string) (Auth::user()?->company?->name ?? '');
    }

    /** @return array<string, string> */
    protected function cabecerasXlsx(): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
