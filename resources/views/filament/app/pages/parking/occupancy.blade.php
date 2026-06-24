@php
    $pct = fn ($n, $total) => $total > 0 ? round($n * 100 / $total, 1) : 0;
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($lot && $summary)
        {{-- KPIs --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-top:14px;">
            <div style="background:#0f172a; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.7; text-transform:uppercase; font-weight:700;">Total espacios</div>
                <div style="font-size:28px; font-weight:900;">{{ $summary['total'] }}</div>
                @if ($summary['capacity'])
                    <div style="font-size:11px; opacity:.7; margin-top:2px;">Capacidad reportada: {{ $summary['capacity'] }}</div>
                @endif
            </div>
            <div style="background:#16a34a; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Libres</div>
                <div style="font-size:28px; font-weight:900;">{{ $summary['free'] }}</div>
                <div style="font-size:11px; opacity:.85; margin-top:2px;">{{ $pct($summary['free'], $summary['total']) }}%</div>
            </div>
            <div style="background:#dc2626; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Ocupados</div>
                <div style="font-size:28px; font-weight:900;">{{ $summary['occupied'] }}</div>
                <div style="font-size:11px; opacity:.85; margin-top:2px;">{{ $pct($summary['occupied'], $summary['total']) }}%</div>
            </div>
            <div style="background:#f59e0b; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Reservados</div>
                <div style="font-size:28px; font-weight:900;">{{ $summary['reserved'] }}</div>
            </div>
            <div style="background:#6b7280; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Mantenimiento</div>
                <div style="font-size:28px; font-weight:900;">{{ $summary['maintenance'] }}</div>
            </div>
        </div>

        {{-- Alerta de aforo --}}
        @php
            $usedRatio = $summary['total'] > 0 ? ($summary['occupied'] / $summary['total']) : 0;
        @endphp
        @if ($usedRatio >= 0.9)
            <div style="margin-top:14px; background:#fee2e2; color:#991b1b; border:1px solid #dc2626; border-radius:8px; padding:10px 14px; font-weight:700;">
                🚨 Aforo crítico — {{ $summary['occupied'] }}/{{ $summary['total'] }} espacios ocupados ({{ $pct($summary['occupied'], $summary['total']) }}%).
            </div>
        @elseif ($usedRatio >= 0.75)
            <div style="margin-top:14px; background:#fef3c7; color:#92400e; border:1px solid #f59e0b; border-radius:8px; padding:10px 14px; font-weight:600;">
                ⚠ Aforo alto — {{ $summary['occupied'] }}/{{ $summary['total'] }} espacios ocupados.
            </div>
        @endif

        {{-- Grid por zona --}}
        @forelse ($zones as $zoneName => $zoneSpaces)
            <div style="margin-top:18px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:6px;">
                    <h3 style="margin:0; font-size:16px; font-weight:800; color:#111827;">{{ $zoneName }}</h3>
                    <div style="font-size:12px; color:#6b7280;">{{ $zoneSpaces->count() }} espacios</div>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(90px, 1fr)); gap:6px;">
                    @foreach ($zoneSpaces as $space)
                        @php
                            $color = $statusColors[$space->status] ?? '#6b7280';
                            $isOcc = $space->status === \App\Models\Parking\ParkingSpace::STATUS_OCCUPIED;
                            $sess = $space->activeSession->first();
                            $tooltip = $isOcc && $sess
                                ? "Placa {$sess->plate} · entró ".$sess->entry_at->format('H:i')
                                : (\App\Models\Parking\ParkingSpace::STATUSES[$space->status] ?? '');
                        @endphp
                        <div title="{{ $tooltip }}" style="position:relative; background:{{ $color }}; color:#fff; border-radius:8px; padding:8px 6px; text-align:center; min-height:70px; display:flex; flex-direction:column; justify-content:center; box-shadow:0 1px 2px rgba(0,0,0,.06);">
                            <div style="font-size:13px; font-weight:800; font-family:ui-monospace, monospace; letter-spacing:1px;">{{ $space->code }}</div>
                            @if ($space->is_accessibility)
                                <div style="position:absolute; top:3px; right:5px; font-size:11px;">♿</div>
                            @endif
                            @if ($isOcc && $sess)
                                <div style="font-size:10px; opacity:.92; font-family:ui-monospace, monospace; margin-top:2px;">{{ $sess->plate }}</div>
                            @elseif ($space->vehicleType)
                                <div style="font-size:9.5px; opacity:.75; margin-top:2px;">{{ $space->vehicleType->name }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div style="margin-top:18px; padding:30px; text-align:center; color:#6b7280; background:#f9fafb; border-radius:10px;">
                Este parqueadero no tiene espacios configurados. Crea espacios en <strong>Parqueadero → Espacios</strong>.
            </div>
        @endforelse
    @else
        <div style="margin-top:18px; padding:30px; text-align:center; color:#6b7280;">
            Selecciona un parqueadero para ver su ocupación.
        </div>
    @endif
</x-filament-panels::page>
