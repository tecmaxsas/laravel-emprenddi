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
@endphp

<x-filament-panels::page>
    {{-- Barra de navegación de semana --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <button type="button" wire:click="prevWeek"
                style="padding:8px 12px; border:1px solid #d1d5db; background:#fff; border-radius:8px; cursor:pointer; font-weight:600; color:#374151;">
                ← Anterior
            </button>
            <button type="button" wire:click="goToday"
                style="padding:8px 12px; border:1px solid #6366f1; background:#6366f1; color:#fff; border-radius:8px; cursor:pointer; font-weight:600;">
                Hoy
            </button>
            <button type="button" wire:click="nextWeek"
                style="padding:8px 12px; border:1px solid #d1d5db; background:#fff; border-radius:8px; cursor:pointer; font-weight:600; color:#374151;">
                Siguiente →
            </button>
        </div>

        <div style="font-weight:700; font-size:15px; color:#111827;">
            {{ $weekStart->locale('es')->isoFormat('D [de] MMMM') }} — {{ $weekEnd->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}
        </div>

        <a href="{{ $createUrl }}"
            style="padding:9px 16px; background:#10b981; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
            + Nueva cita
        </a>
    </div>

    {{-- Grid semanal: 7 columnas con scroll horizontal en móvil --}}
    <div style="overflow-x:auto;">
        <div style="display:grid; grid-template-columns:repeat(7, minmax(160px, 1fr)); gap:8px; min-width:900px;">
            @foreach ($days as $day)
                @php $isToday = $day['is_today']; @endphp
                <div style="border:1px solid {{ $isToday ? '#6366f1' : '#e5e7eb' }}; border-radius:10px; overflow:hidden; background:{{ $isToday ? '#eef2ff' : '#fafafa' }};">
                    {{-- Encabezado del día --}}
                    <div style="padding:8px 10px; text-align:center; border-bottom:1px solid #e5e7eb; background:{{ $isToday ? '#6366f1' : '#f3f4f6' }}; color:{{ $isToday ? '#fff' : '#374151' }};">
                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700;">
                            {{ $day['date']->locale('es')->isoFormat('ddd') }}
                        </div>
                        <div style="font-size:18px; font-weight:800; line-height:1.1;">
                            {{ $day['date']->format('d') }}
                        </div>
                    </div>

                    {{-- Citas del día --}}
                    <div style="padding:8px; display:flex; flex-direction:column; gap:6px; min-height:80px;">
                        @forelse ($day['appointments'] as $appt)
                            @php
                                $color = $colorHex[$statusColors[$appt->status] ?? 'gray'] ?? '#6b7280';
                                $editUrl = route('filament.app.resources.appointments.edit', $appt);
                            @endphp
                            <a href="{{ $editUrl }}"
                                style="display:block; text-decoration:none; background:#fff; border:1px solid #e5e7eb; border-left:4px solid {{ $color }}; border-radius:8px; padding:7px 9px; box-shadow:0 1px 2px rgba(0,0,0,.04);">
                                <div style="font-size:13px; font-weight:800; color:#111827;">
                                    {{ $appt->starts_at->format('H:i') }}
                                    <span style="font-weight:500; color:#6b7280;">– {{ $appt->ends_at->format('H:i') }}</span>
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1f2937; margin-top:2px;">
                                    {{ $appt->client?->name ?? 'Sin cliente' }}
                                </div>
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
</x-filament-panels::page>
