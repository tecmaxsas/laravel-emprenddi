@php
    use Illuminate\Support\Carbon;

    $plata = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
    $pct = fn ($n) => number_format((float) $n, 1, ',', '.').'%';

    $diaCorto = ['Mon' => 'lunes', 'Tue' => 'martes', 'Wed' => 'miércoles', 'Thu' => 'jueves',
                 'Fri' => 'viernes', 'Sat' => 'sábado', 'Sun' => 'domingo'];

    // Los días flojos: por debajo del 40 % todavía hay tiempo de hacer algo,
    // y de eso se trata este reporte. Marcarlos es lo que lo vuelve accionable
    // en vez de una tabla más.
    $flojo = 40;
@endphp

<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    <div class="hr-toolbar">
        <button type="button" class="hr-btn hr-btn-print" onclick="window.print()">
            🖨️ Imprimir / Guardar PDF
        </button>
    </div>

    <div class="hr-print">
        @if ($available === 0)
            <div class="hr-empty">
                Este hotel no tiene habitaciones vendibles, así que no hay nada que pronosticar.
            </div>
        @elseif ($rows->isEmpty())
            <div class="hr-empty">Sin datos para el período.</div>
        @else
            <div class="hr-kpis">
                <div class="hr-kpi" style="background:#4f46e5;">
                    <div class="hr-kpi-k">Ocupación prevista</div>
                    <div class="hr-kpi-v">{{ $pct($totals['ocupacion']) }}</div>
                    <div class="hr-kpi-h">
                        {{ number_format($totals['vendidas'], 0, ',', '.') }} de
                        {{ number_format($totals['noches_disponibles'], 0, ',', '.') }} noches
                    </div>
                </div>
                <div class="hr-kpi" style="background:#16a34a;">
                    <div class="hr-kpi-k">Ingreso comprometido</div>
                    <div class="hr-kpi-v">{{ $plata($totals['ingreso']) }}</div>
                    <div class="hr-kpi-h">Solo alojamiento, {{ $totals['dias'] }} día(s)</div>
                </div>
                <div class="hr-kpi" style="background:#475569;">
                    <div class="hr-kpi-k">ADR previsto</div>
                    <div class="hr-kpi-v">{{ $plata($totals['adr']) }}</div>
                    <div class="hr-kpi-h">A la tarifa de cada fecha</div>
                </div>
                <div class="hr-kpi" style="background:#0f172a;">
                    <div class="hr-kpi-k">RevPAR previsto</div>
                    <div class="hr-kpi-v">{{ $plata($totals['revpar']) }}</div>
                    <div class="hr-kpi-h">Por habitación disponible</div>
                </div>
                <div class="hr-kpi" style="background:#b45309;">
                    <div class="hr-kpi-k">Noches por vender</div>
                    <div class="hr-kpi-v">{{ number_format($totals['libres'], 0, ',', '.') }}</div>
                    <div class="hr-kpi-h">Lo que todavía se puede hacer</div>
                </div>
            </div>

            <div class="hr-card">
                <table class="hr-table">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th class="hr-r">Alojados</th>
                            <th class="hr-r">Reservados</th>
                            <th class="hr-r">Libres</th>
                            <th>Ocupación</th>
                            <th class="hr-r">Ingreso previsto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $fila)
                            @php $f = Carbon::parse($fila['dia']); @endphp
                            <tr @if ($fila['finde']) class="hr-finde" @endif>
                                <td>
                                    <span class="hr-mono">{{ $f->format('d/m') }}</span>
                                    <span class="hr-dim">{{ $diaCorto[$f->format('D')] ?? '' }}</span>
                                </td>
                                <td class="hr-r">{{ $fila['alojados'] ?: '—' }}</td>
                                <td class="hr-r">{{ $fila['reservados'] ?: '—' }}</td>
                                <td class="hr-r hr-strong">{{ $fila['libres'] }}</td>
                                <td>
                                    <div class="hr-bar">
                                        <span style="width: {{ min(100, $fila['ocupacion']) }}%;
                                              {{ $fila['ocupacion'] < $flojo ? 'background:#b45309;' : '' }}"></span>
                                        <b>{{ $pct($fila['ocupacion']) }}</b>
                                    </div>
                                </td>
                                <td class="hr-r hr-strong">{{ $plata($fila['ingreso']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="hr-r">—</th>
                            <th class="hr-r">—</th>
                            <th class="hr-r">{{ number_format($totals['libres'], 0, ',', '.') }}</th>
                            <th>{{ $pct($totals['ocupacion']) }}</th>
                            <th class="hr-r">{{ $plata($totals['ingreso']) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="hr-dim" style="margin-top:8px; font-size:11.5px;">
                Las barras en ámbar son los días por debajo del {{ $flojo }} %: ahí es donde todavía se
                puede hacer algo. El ingreso es lo <strong>comprometido</strong> a la tarifa de cada
                fecha —incluidas las temporadas—, no lo facturado: esas noches aún no se han cobrado y
                una reserva puede cancelarse.
            </p>
        @endif
    </div>

    @include('filament.app.pages.hotel.reports.partials.report-styles')
</x-filament-panels::page>
