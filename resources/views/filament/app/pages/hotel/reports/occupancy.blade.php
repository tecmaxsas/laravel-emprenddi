@php
    $plata = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
    $pct = fn ($n) => number_format((float) $n, 1, ',', '.').'%';
@endphp

<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    <div class="hr-toolbar">
        <a href="{{ $this->getExportUrl() }}" target="_blank" rel="noopener" class="hr-btn hr-btn-excel">
            📊 Excel por día
        </a>
        <a href="{{ $this->getStaysExportUrl() }}" target="_blank" rel="noopener" class="hr-btn hr-btn-detail">
            📋 Excel del detalle (estadías)
        </a>
        <button type="button" class="hr-btn hr-btn-print" onclick="window.print()">
            🖨️ Imprimir / Guardar PDF
        </button>
    </div>

    <div class="hr-print">
        <div class="hr-kpis">
            {{-- RevPAR primero a propósito: es la métrica que de verdad dice
                 cómo va el hotel, porque mezcla tarifa y ocupación. Un ADR
                 altísimo con el hotel vacío no es un buen mes. --}}
            <div class="hr-kpi" style="background:#4f46e5;">
                <div class="hr-kpi-k">RevPAR</div>
                <div class="hr-kpi-v">{{ $plata($totals['revpar']) }}</div>
                <div class="hr-kpi-h">Ingreso por habitación disponible</div>
            </div>
            <div class="hr-kpi" style="background:#0f172a;">
                <div class="hr-kpi-k">Ocupación</div>
                <div class="hr-kpi-v">{{ $pct($totals['ocupacion']) }}</div>
                <div class="hr-kpi-h">
                    {{ number_format($totals['vendidas'], 0, ',', '.') }} de
                    {{ number_format($totals['noches_disponibles'], 0, ',', '.') }} noches
                </div>
            </div>
            <div class="hr-kpi" style="background:#475569;">
                <div class="hr-kpi-k">ADR</div>
                <div class="hr-kpi-v">{{ $plata($totals['adr']) }}</div>
                <div class="hr-kpi-h">Tarifa media de lo vendido</div>
            </div>
            <div class="hr-kpi" style="background:#16a34a;">
                <div class="hr-kpi-k">Alojamiento</div>
                <div class="hr-kpi-v">{{ $plata($totals['alojamiento']) }}</div>
                <div class="hr-kpi-h">{{ $totals['dias'] }} día(s) del período</div>
            </div>
            <div class="hr-kpi" style="background:#0ea5e9;">
                <div class="hr-kpi-k">Consumos</div>
                <div class="hr-kpi-v">{{ $plata($totals['consumos']) }}</div>
                <div class="hr-kpi-h">Minibar, restaurante, servicios</div>
            </div>
            <div class="hr-kpi" style="background:#b45309;">
                <div class="hr-kpi-k">Total</div>
                <div class="hr-kpi-v">{{ $plata($totals['total']) }}</div>
                <div class="hr-kpi-h">Alojamiento + consumos</div>
            </div>
        </div>

        @if ($available === 0)
            <div class="hr-empty">
                Este hotel no tiene habitaciones vendibles, así que no hay nada que medir.
                Créalas en <strong>Hotel → Habitaciones</strong>.
            </div>
        @elseif ($rows->isEmpty())
            <div class="hr-empty">Sin movimiento en el período seleccionado.</div>
        @else
            <div class="hr-card">
                <table class="hr-table">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th class="hr-r">Habitaciones</th>
                            <th class="hr-r">Vendidas</th>
                            <th>Ocupación</th>
                            <th class="hr-r">Alojamiento</th>
                            <th class="hr-r">ADR</th>
                            <th class="hr-r">RevPAR</th>
                            <th class="hr-r">Consumos</th>
                            <th class="hr-r">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $fila)
                            <tr>
                                <td class="hr-mono">{{ $fila['dia'] }}</td>
                                <td class="hr-r hr-dim">{{ $fila['disponibles'] }}</td>
                                <td class="hr-r">{{ $fila['vendidas'] }}</td>
                                <td>
                                    <div class="hr-bar">
                                        <span style="width: {{ min(100, $fila['ocupacion']) }}%"></span>
                                        <b>{{ $pct($fila['ocupacion']) }}</b>
                                    </div>
                                </td>
                                <td class="hr-r hr-strong">{{ $plata($fila['alojamiento']) }}</td>
                                <td class="hr-r">{{ $plata($fila['adr']) }}</td>
                                <td class="hr-r">{{ $plata($fila['revpar']) }}</td>
                                <td class="hr-r hr-dim">{{ $plata($fila['consumos']) }}</td>
                                <td class="hr-r hr-strong">{{ $plata($fila['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total {{ $from }} a {{ $to }}</th>
                            <th class="hr-r">{{ $available }}</th>
                            <th class="hr-r">{{ number_format($totals['vendidas'], 0, ',', '.') }}</th>
                            <th>{{ $pct($totals['ocupacion']) }}</th>
                            <th class="hr-r">{{ $plata($totals['alojamiento']) }}</th>
                            <th class="hr-r">{{ $plata($totals['adr']) }}</th>
                            <th class="hr-r">{{ $plata($totals['revpar']) }}</th>
                            <th class="hr-r">{{ $plata($totals['consumos']) }}</th>
                            <th class="hr-r">{{ $plata($totals['total']) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="hr-dim" style="margin-top:8px; font-size:11.5px;">
                La columna «Habitaciones» es el inventario vendible de hoy: el sistema no guarda cuántas
                habitaciones tenía el hotel en cada fecha, así que un hotel que amplió un piso verá los
                meses anteriores calculados contra el inventario actual.
            </p>
        @endif
    </div>

    @include('filament.app.pages.hotel.reports.partials.report-styles')
</x-filament-panels::page>
