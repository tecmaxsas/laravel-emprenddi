@php
    $statusColors = \App\Models\Appointment::STATUS_COLORS;
    $statusLabels = \App\Models\Appointment::STATUSES;
    $colorHex = [
        'gray' => '#6b7280',
        'info' => '#0ea5e9',
        'warning' => '#f59e0b',
        'success' => '#10b981',
        'danger' => '#ef4444',
        'primary' => '#6366f1',
    ];
    $createUrl = route('filament.app.resources.appointments.create');
    $weekdays = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
@endphp

<x-filament-panels::page>
    {{-- Barra superior: navegación + modo + nueva cita --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <button type="button" wire:click="prev"
                style="padding:8px 12px; border:1px solid #d1d5db; background:#fff; border-radius:8px; cursor:pointer; font-weight:600; color:#374151;">
                ← Anterior
            </button>
            <button type="button" wire:click="goToday"
                style="padding:8px 12px; border:1px solid #6366f1; background:#6366f1; color:#fff; border-radius:8px; cursor:pointer; font-weight:600;">
                Hoy
            </button>
            <button type="button" wire:click="next"
                style="padding:8px 12px; border:1px solid #d1d5db; background:#fff; border-radius:8px; cursor:pointer; font-weight:600; color:#374151;">
                Siguiente →
            </button>

            {{-- Toggle Mes / Semana --}}
            <div style="display:inline-flex; border:1px solid #d1d5db; border-radius:8px; overflow:hidden; margin-left:4px;">
                <button type="button" wire:click="setMode('month')"
                    style="padding:8px 14px; border:0; cursor:pointer; font-weight:700; {{ $mode === 'month' ? 'background:#111827; color:#fff;' : 'background:#fff; color:#374151;' }}">
                    Mes
                </button>
                <button type="button" wire:click="setMode('week')"
                    style="padding:8px 14px; border:0; cursor:pointer; font-weight:700; {{ $mode === 'week' ? 'background:#111827; color:#fff;' : 'background:#fff; color:#374151;' }}">
                    Semana
                </button>
            </div>
        </div>

        <div style="font-weight:700; font-size:15px; color:#111827;">{{ $periodLabel }}</div>

        <a href="{{ $createUrl }}"
            style="padding:9px 16px; background:#10b981; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
            + Nueva cita
        </a>
    </div>

    @if ($mode === 'month')
        {{-- ============ VISTA MES ============ --}}
        <div style="overflow-x:auto;">
            <div style="min-width:760px;">
                {{-- Cabecera de días --}}
                <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:6px; margin-bottom:6px;">
                    @foreach ($weekdays as $wd)
                        <div style="text-align:center; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#6b7280;">{{ $wd }}</div>
                    @endforeach
                </div>

                @foreach ($weeks as $week)
                    <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:6px; margin-bottom:6px;">
                        @foreach ($week as $day)
                            @php
                                $appts = $day['appointments'];
                                $isToday = $day['is_today'];
                                $inMonth = $day['in_month'];
                            @endphp
                            <div style="min-height:104px; border:1px solid {{ $isToday ? '#6366f1' : '#e5e7eb' }}; border-radius:10px; padding:5px; background:{{ $isToday ? '#eef2ff' : ($inMonth ? '#fff' : '#f9fafb') }}; display:flex; flex-direction:column; gap:3px;">
                                <div style="display:flex; align-items:center; justify-content:space-between;">
                                    <button type="button" wire:click="openDay('{{ $day['key'] }}')"
                                        style="border:0; background:transparent; cursor:pointer; font-size:13px; font-weight:800; padding:0; color:{{ $isToday ? '#6366f1' : ($inMonth ? '#111827' : '#9ca3af') }};">
                                        {{ $day['date']->format('d') }}
                                    </button>
                                    @if ($appts->count() > 0)
                                        <span style="font-size:10px; font-weight:700; color:#6b7280;">{{ $appts->count() }}</span>
                                    @endif
                                </div>

                                @foreach ($appts->take(3) as $appt)
                                    @php $color = $colorHex[$statusColors[$appt->status] ?? 'gray'] ?? '#6b7280'; @endphp
                                    <a href="{{ route('filament.app.resources.appointments.edit', $appt) }}"
                                        title="{{ $appt->starts_at->format('H:i') }} · {{ $appt->client?->name ?? 'Sin cliente' }}{{ $appt->service ? ' · '.$appt->service->name : '' }}"
                                        style="display:block; text-decoration:none; font-size:11px; line-height:1.25; padding:2px 5px; border-radius:5px; background:{{ $color }}1a; color:#1f2937; border-left:3px solid {{ $color }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <span style="font-weight:800; color:{{ $color }};">{{ $appt->starts_at->format('H:i') }}</span>
                                        {{ $appt->client?->name ?? 'Sin cliente' }}
                                    </a>
                                @endforeach

                                @if ($appts->count() > 3)
                                    <button type="button" wire:click="openDay('{{ $day['key'] }}')"
                                        style="border:0; background:transparent; cursor:pointer; text-align:left; font-size:10px; font-weight:700; color:#6366f1; padding:0;">
                                        + {{ $appts->count() - 3 }} más
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @else
        {{-- ============ VISTA SEMANA ============ --}}
        <div style="overflow-x:auto;">
            <div style="display:grid; grid-template-columns:repeat(7, minmax(160px, 1fr)); gap:8px; min-width:900px;">
                @foreach ($days as $day)
                    @php $isToday = $day['is_today']; @endphp
                    <div style="border:1px solid {{ $isToday ? '#6366f1' : '#e5e7eb' }}; border-radius:10px; overflow:hidden; background:{{ $isToday ? '#eef2ff' : '#fafafa' }};">
                        <div style="padding:8px 10px; text-align:center; border-bottom:1px solid #e5e7eb; background:{{ $isToday ? '#6366f1' : '#f3f4f6' }}; color:{{ $isToday ? '#fff' : '#374151' }};">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700;">
                                {{ $day['date']->locale('es')->isoFormat('ddd') }}
                            </div>
                            <div style="font-size:18px; font-weight:800; line-height:1.1;">{{ $day['date']->format('d') }}</div>
                        </div>

                        <div style="padding:8px; display:flex; flex-direction:column; gap:6px; min-height:80px;">
                            @forelse ($day['appointments'] as $appt)
                                @php $color = $colorHex[$statusColors[$appt->status] ?? 'gray'] ?? '#6b7280'; @endphp
                                <a href="{{ route('filament.app.resources.appointments.edit', $appt) }}"
                                    style="display:block; text-decoration:none; background:#fff; border:1px solid #e5e7eb; border-left:4px solid {{ $color }}; border-radius:8px; padding:7px 9px; box-shadow:0 1px 2px rgba(0,0,0,.04);">
                                    <div style="font-size:13px; font-weight:800; color:#111827;">
                                        {{ $appt->starts_at->format('H:i') }}
                                        <span style="font-weight:500; color:#6b7280;">– {{ $appt->ends_at->format('H:i') }}</span>
                                    </div>
                                    <div style="font-size:13px; font-weight:600; color:#1f2937; margin-top:2px;">{{ $appt->client?->name ?? 'Sin cliente' }}</div>
                                    @if ($appt->service)
                                        <div style="font-size:12px; color:#4b5563;">{{ $appt->service->name }}</div>
                                    @endif
                                    @if ($appt->employee)
                                        <div style="font-size:11px; color:#6b7280; margin-top:2px;">👤 {{ $appt->employee->fullName() }}</div>
                                    @endif
                                    <div style="display:inline-block; margin-top:5px; font-size:10px; font-weight:700; color:{{ $color }}; background:{{ $color }}1a; padding:1px 7px; border-radius:999px;">
                                        {{ $statusLabels[$appt->status] ?? $appt->status }}
                                    </div>
                                </a>
                            @empty
                                <div style="text-align:center; color:#9ca3af; font-size:12px; padding:12px 0;">—</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
