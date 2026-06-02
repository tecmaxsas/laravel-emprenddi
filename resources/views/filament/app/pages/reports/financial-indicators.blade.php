@php
    $fmt = function ($v, $format) {
        if ($format === 'money') return '$ ' . number_format((float) $v, 0, ',', '.');
        if ($format === 'pct')   return number_format((float) $v, 1, ',', '.') . '%';
        if ($format === 'days')  return number_format((float) $v, 0, ',', '.') . ' días';
        return number_format((float) $v, 2, ',', '.');
    };
    $statusColor = [
        'good'    => ['bg' => '#dcfce7', 'fg' => '#166534', 'label' => 'Saludable'],
        'warning' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'Atención'],
        'bad'     => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'label' => 'Crítico'],
        'neutral' => ['bg' => '#f1f5f9', 'fg' => '#475569', 'label' => 'Informativo'],
    ];
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if (! empty($indicators))
        @foreach ($indicators as $group)
            <div style="margin-top:16px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                <div style="padding:12px 18px; background:{{ $group['color'] }}; color:#fff;">
                    <div style="font-size:11px; opacity:.85; text-transform:uppercase; letter-spacing:.05em; font-weight:700;">Indicadores</div>
                    <div style="font-size:18px; font-weight:800;">{{ $group['title'] }}</div>
                    <div style="font-size:12px; opacity:.85; font-weight:500;">{{ $group['description'] }}</div>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:0;">
                    @foreach ($group['items'] as $item)
                        @php $s = $statusColor[$item['status']] ?? $statusColor['neutral']; @endphp
                        <div style="padding:14px 16px; border-right:1px solid #f3f4f6; border-bottom:1px solid #f3f4f6;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:6px;">
                                <div style="font-weight:700; color:#1f2937; font-size:13px;">{{ $item['name'] }}</div>
                                <span style="background:{{ $s['bg'] }}; color:{{ $s['fg'] }}; font-size:9.5px; font-weight:800; padding:2px 6px; border-radius:999px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap;">{{ $s['label'] }}</span>
                            </div>
                            <div style="font-size:24px; font-weight:900; color:{{ $s['fg'] }}; font-family:ui-monospace, monospace; line-height:1.1;">
                                {{ $fmt($item['value'], $item['format']) }}
                            </div>
                            <div style="font-size:11px; color:#6b7280; margin-top:6px; font-family:ui-monospace, monospace;">{{ $item['formula'] }}</div>
                            <div style="font-size:11px; color:#9ca3af; margin-top:2px;">📏 {{ $item['benchmark'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="margin-top:16px; font-size:11px; color:#6b7280; padding:0 4px;">
            * El balance se toma a la fecha "hasta"; los indicadores de flujo (ventas, utilidad, costo) usan el rango completo. Cartera = saldo cuentas 13xx; Inventarios = saldo 14xx; Gastos financieros = saldo 5305. Los umbrales de "saludable / atención / crítico" son referencias generales — ajústalos a tu sector y al criterio de tu contador.
        </div>
    @endif
</x-filament-panels::page>
