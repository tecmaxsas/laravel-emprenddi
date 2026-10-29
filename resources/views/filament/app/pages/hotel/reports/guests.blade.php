@php
    use App\Support\ClockFormat;
@endphp

<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    <div class="hr-toolbar">
        <a href="{{ $this->getExportUrl() }}" target="_blank" rel="noopener" class="hr-btn hr-btn-excel">
            📊 Descargar Excel
        </a>
        <button type="button" class="hr-btn hr-btn-print" onclick="window.print()">
            🖨️ Imprimir / Guardar PDF
        </button>
    </div>

    <div class="hr-print">
        <div class="hr-kpis">
            <div class="hr-kpi" style="background:#0f172a;">
                <div class="hr-kpi-k">Huéspedes</div>
                <div class="hr-kpi-v">{{ number_format($totals['huespedes'], 0, ',', '.') }}</div>
                <div class="hr-kpi-h">Personas registradas</div>
            </div>
            <div class="hr-kpi" style="background:#4f46e5;">
                <div class="hr-kpi-k">Estadías</div>
                <div class="hr-kpi-v">{{ number_format($totals['estadias'], 0, ',', '.') }}</div>
                <div class="hr-kpi-h">Folios del período</div>
            </div>
            <div class="hr-kpi" style="background:#b45309;">
                <div class="hr-kpi-k">Extranjeros</div>
                <div class="hr-kpi-v">{{ number_format($totals['extranjeros'], 0, ',', '.') }}</div>
                <div class="hr-kpi-h">Para el reporte a Migración</div>
            </div>
        </div>

        @if ($rows->isEmpty())
            <div class="hr-empty">
                Sin huéspedes registrados en el período.
                Si hubo estadías pero no aparecen, es que se alojaron sin registrar al huésped:
                el titular se captura en el check-in.
            </div>
        @else
            <div class="hr-card">
                <table class="hr-table">
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Hab.</th>
                            <th>Nombre</th>
                            <th>Documento</th>
                            <th>Nacionalidad</th>
                            <th>Entrada</th>
                            <th class="hr-r">Noches</th>
                            <th>Procedencia</th>
                            <th>Destino</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $fila)
                            <tr>
                                <td class="hr-mono">{{ $fila['folio'] }}</td>
                                <td class="hr-mono">{{ $fila['habitacion'] }}</td>
                                <td>
                                    <span class="hr-strong">{{ $fila['nombre'] }}</span>
                                    @if ($fila['titular'])
                                        <span class="hr-dim">· titular</span>
                                    @endif
                                </td>
                                <td class="hr-mono">{{ $fila['documento'] }}</td>
                                <td>
                                    {{ $fila['nacionalidad'] ?: '—' }}
                                    @if ($fila['extranjero'])
                                        <span class="hr-dim">· extranjero</span>
                                    @endif
                                </td>
                                <td>{{ $fila['entrada']?->format(ClockFormat::datetime()) }}</td>
                                <td class="hr-r">{{ $fila['noches'] }}</td>
                                <td>{{ $fila['procedencia'] ?: '—' }}</td>
                                <td>{{ $fila['destino'] ?: '—' }}</td>
                                <td>{{ $fila['motivo'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="hr-dim" style="margin-top:8px; font-size:11.5px;">
                Período {{ $from }} a {{ $to }}, por fecha de entrada.
            </p>
        @endif
    </div>

    @include('filament.app.pages.hotel.reports.partials.report-styles')
</x-filament-panels::page>
