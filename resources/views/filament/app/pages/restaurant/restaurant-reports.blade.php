<x-filament-panels::page>
    @php
        $tips = $this->tipsReport;
        $top = $this->topItems;
        $times = $this->prepTimes;
        $sum = $this->summary;
    @endphp

    {{-- Filtros + presets --}}
    <div style="background:#ffffff; border:1px solid #d1d5db; border-radius:12px; padding:14px; margin-bottom:14px;">
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase;">Desde</label>
                <input type="date" wire:model.live="dateFrom"
                       style="padding:8px 10px; border-radius:8px; border:1px solid #d1d5db; font-size:13px; color:#111827; background:#ffffff;" />
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase;">Hasta</label>
                <input type="date" wire:model.live="dateTo"
                       style="padding:8px 10px; border-radius:8px; border:1px solid #d1d5db; font-size:13px; color:#111827; background:#ffffff;" />
            </div>
            <div style="display:flex; gap:4px; flex-wrap:wrap; margin-left:auto;">
                @foreach ([
                    'today' => 'Hoy',
                    'yesterday' => 'Ayer',
                    'week' => 'Semana',
                    'month' => 'Mes',
                    'last30' => '30 días',
                ] as $key => $label)
                    <button type="button" wire:click="setPreset('{{ $key }}')"
                            style="padding:6px 12px; border-radius:6px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; font-weight:600; cursor:pointer; font-size:12px;">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Resumen general --}}
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:18px;">
        <div style="background:#dbeafe; border:1px solid #93c5fd; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#1e40af; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Órdenes cerradas</div>
            <div style="font-size:24px; font-weight:800; color:#1e3a8a; margin-top:6px;">{{ number_format($sum['orders_closed'], 0, ',', '.') }}</div>
        </div>
        <div style="background:#dcfce7; border:1px solid #86efac; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#15803d; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Ventas netas</div>
            <div style="font-size:24px; font-weight:800; color:#14532d; margin-top:6px;">${{ number_format($sum['net_sales'], 0, ',', '.') }}</div>
        </div>
        <div style="background:#fef3c7; border:1px solid #fcd34d; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#92400e; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Propinas</div>
            <div style="font-size:24px; font-weight:800; color:#78350f; margin-top:6px;">${{ number_format($sum['tips_total'], 0, ',', '.') }}</div>
        </div>
        <div style="background:#f3e8ff; border:1px solid #d8b4fe; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#6b21a8; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Total bruto</div>
            <div style="font-size:24px; font-weight:800; color:#581c87; margin-top:6px;">${{ number_format($sum['gross_total'], 0, ',', '.') }}</div>
        </div>
    </div>

    {{-- Propinas por mesero --}}
    <div style="background:#ffffff; border:1px solid #d1d5db; border-radius:12px; padding:18px; margin-bottom:18px;">
        <h2 style="margin:0 0 14px 0; font-size:16px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px;">
            💵 Propinas por mesero
            <span style="font-size:11px; color:#6b7280; font-weight:500;">({{ $tips->count() }} meseros)</span>
        </h2>

        @if ($tips->isEmpty())
            <div style="padding:24px; text-align:center; color:#9ca3af; font-size:13px;">
                No hay órdenes cerradas en este rango.
            </div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f9fafb; border-bottom:2px solid #e5e7eb;">
                            <th style="text-align:left; padding:10px 12px; font-weight:700; color:#374151;">Mesero</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Órdenes</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Ventas netas</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Propinas</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">% Propina</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Total bruto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tips as $row)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:10px 12px; color:#111827; font-weight:600;">
                                    {{ $row->server_name ?? '— sin asignar —' }}
                                </td>
                                <td style="padding:10px 12px; text-align:right; color:#6b7280;">{{ $row->orders_count }}</td>
                                <td style="padding:10px 12px; text-align:right; color:#374151;">${{ number_format((float) $row->net_sales, 0, ',', '.') }}</td>
                                <td style="padding:10px 12px; text-align:right; color:#92400e; font-weight:700;">${{ number_format((float) $row->tips_total, 0, ',', '.') }}</td>
                                <td style="padding:10px 12px; text-align:right; color:#6b7280;">{{ number_format($row->tip_pct, 1) }}%</td>
                                <td style="padding:10px 12px; text-align:right; color:#111827; font-weight:600;">${{ number_format((float) $row->gross_total, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Top items vendidos --}}
    <div style="background:#ffffff; border:1px solid #d1d5db; border-radius:12px; padding:18px; margin-bottom:18px;">
        <h2 style="margin:0 0 14px 0; font-size:16px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px;">
            🍽️ Top items vendidos
            <span style="font-size:11px; color:#6b7280; font-weight:500;">(top {{ $topItemsLimit }})</span>
        </h2>

        @if ($top->isEmpty())
            <div style="padding:24px; text-align:center; color:#9ca3af; font-size:13px;">
                No hay items vendidos en este rango.
            </div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f9fafb; border-bottom:2px solid #e5e7eb;">
                            <th style="text-align:left; padding:10px 12px; font-weight:700; color:#374151; width:40px;">#</th>
                            <th style="text-align:left; padding:10px 12px; font-weight:700; color:#374151;">Item</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Líneas</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Unidades</th>
                            <th style="text-align:right; padding:10px 12px; font-weight:700; color:#374151;">Ingresos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($top as $i => $row)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:10px 12px; color:#9ca3af; font-weight:700;">{{ $i + 1 }}</td>
                                <td style="padding:10px 12px; color:#111827; font-weight:600;">{{ $row->description }}</td>
                                <td style="padding:10px 12px; text-align:right; color:#6b7280;">{{ $row->line_count }}</td>
                                <td style="padding:10px 12px; text-align:right; color:#111827; font-weight:700;">{{ number_format((float) $row->units_sold, 0) }}</td>
                                <td style="padding:10px 12px; text-align:right; color:#059669; font-weight:700;">${{ number_format((float) $row->revenue, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Tiempos promedio de preparación --}}
    <div style="background:#ffffff; border:1px solid #d1d5db; border-radius:12px; padding:18px; margin-bottom:18px;">
        <h2 style="margin:0 0 14px 0; font-size:16px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px;">
            ⏱️ Tiempos promedio de cocina
            <span style="font-size:11px; color:#6b7280; font-weight:500;">({{ $times['items_total'] }} items con datos)</span>
        </h2>

        @if ($times['items_total'] === 0)
            <div style="padding:24px; text-align:center; color:#9ca3af; font-size:13px;">
                No hay items con timestamps de cocina en este rango. Necesitas órdenes que hayan pasado por el KDS.
            </div>
        @else
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:10px;">
                <div style="padding:14px; background:#dbeafe; border:1px solid #93c5fd; border-radius:8px;">
                    <div style="font-size:11px; color:#1e40af; font-weight:700; text-transform:uppercase;">Enviado → Preparando</div>
                    <div style="font-size:22px; font-weight:800; color:#1e3a8a; margin-top:6px;">
                        {{ $times['avg_sent_to_preparing'] }} <span style="font-size:13px; font-weight:600;">min</span>
                    </div>
                    <div style="font-size:10px; color:#1e40af; margin-top:2px;">
                        Cuanto demora cocina en empezar después de recibir
                    </div>
                </div>

                <div style="padding:14px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px;">
                    <div style="font-size:11px; color:#92400e; font-weight:700; text-transform:uppercase;">Preparando → Listo</div>
                    <div style="font-size:22px; font-weight:800; color:#78350f; margin-top:6px;">
                        {{ $times['avg_preparing_to_ready'] }} <span style="font-size:13px; font-weight:600;">min</span>
                    </div>
                    <div style="font-size:10px; color:#92400e; margin-top:2px;">
                        Tiempo neto de cocción
                    </div>
                </div>

                <div style="padding:14px; background:#dcfce7; border:1px solid #86efac; border-radius:8px;">
                    <div style="font-size:11px; color:#15803d; font-weight:700; text-transform:uppercase;">Listo → Servido</div>
                    <div style="font-size:22px; font-weight:800; color:#14532d; margin-top:6px;">
                        {{ $times['avg_ready_to_served'] }} <span style="font-size:13px; font-weight:600;">min</span>
                    </div>
                    <div style="font-size:10px; color:#15803d; margin-top:2px;">
                        Cuanto tarda el mesero en llevarlo a la mesa
                    </div>
                </div>

                <div style="padding:14px; background:#f3e8ff; border:2px solid #a855f7; border-radius:8px;">
                    <div style="font-size:11px; color:#6b21a8; font-weight:700; text-transform:uppercase;">Total (enviado → servido)</div>
                    <div style="font-size:22px; font-weight:800; color:#581c87; margin-top:6px;">
                        {{ $times['avg_total'] }} <span style="font-size:13px; font-weight:600;">min</span>
                    </div>
                    <div style="font-size:10px; color:#6b21a8; margin-top:2px;">
                        Métrica más importante: experiencia del cliente
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
