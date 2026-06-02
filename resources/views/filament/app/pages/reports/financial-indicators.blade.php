@php
    $fmt = function ($v, $format) {
        if ($format === 'money') return '$ ' . number_format((float) $v, 0, ',', '.');
        if ($format === 'pct')   return number_format((float) $v, 1, ',', '.') . '%';
        if ($format === 'days')  return number_format((float) $v, 0, ',', '.') . ' días';
        return number_format((float) $v, 2, ',', '.');
    };
    $statusLabel = [
        'good'    => 'Saludable',
        'warning' => 'Atención',
        'bad'     => 'Crítico',
        'neutral' => 'Informativo',
    ];
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <style>
        .fi-card {
            --fi-bg: #ffffff; --fi-text: #0f172a; --fi-text-muted: #64748b; --fi-border: #e5e7eb;
            --fi-cell-border: #f3f4f6;
            --fi-good-bg: #dcfce7; --fi-good-fg: #166534;
            --fi-warn-bg: #fef3c7; --fi-warn-fg: #92400e;
            --fi-bad-bg: #fee2e2; --fi-bad-fg: #991b1b;
            --fi-neut-bg: #f1f5f9; --fi-neut-fg: #475569;
            background: var(--fi-bg); color: var(--fi-text);
            border: 1px solid var(--fi-border); border-radius: 12px;
            overflow: hidden; margin-top: 16px;
        }
        .dark .fi-card {
            --fi-bg: #1e293b; --fi-text: #e2e8f0; --fi-text-muted: #94a3b8; --fi-border: #334155;
            --fi-cell-border: #2c3a52;
            --fi-good-bg: #14532d; --fi-good-fg: #bbf7d0;
            --fi-warn-bg: #78350f; --fi-warn-fg: #fde68a;
            --fi-bad-bg: #7f1d1d; --fi-bad-fg: #fecaca;
            --fi-neut-bg: #334155; --fi-neut-fg: #cbd5e1;
        }
        .fi-head { padding:12px 18px; color:#fff; }
        .fi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:0; }
        .fi-item { padding:14px 16px; border-right:1px solid var(--fi-cell-border); border-bottom:1px solid var(--fi-cell-border); }
        .fi-item-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:6px; }
        .fi-item-name { font-weight:700; color: var(--fi-text); font-size:13px; }
        .fi-pill { font-size:9.5px; font-weight:800; padding:2px 6px; border-radius:999px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
        .fi-pill-good { background: var(--fi-good-bg); color: var(--fi-good-fg); }
        .fi-pill-warning { background: var(--fi-warn-bg); color: var(--fi-warn-fg); }
        .fi-pill-bad { background: var(--fi-bad-bg); color: var(--fi-bad-fg); }
        .fi-pill-neutral { background: var(--fi-neut-bg); color: var(--fi-neut-fg); }
        .fi-value { font-size:24px; font-weight:900; font-family:ui-monospace, "SF Mono", Menlo, Consolas, monospace; line-height:1.1; }
        .fi-value-good { color: var(--fi-good-fg); }
        .fi-value-warning { color: var(--fi-warn-fg); }
        .fi-value-bad { color: var(--fi-bad-fg); }
        .fi-value-neutral { color: var(--fi-text); }
        .fi-formula { font-size:11px; color: var(--fi-text-muted); margin-top:6px; font-family:ui-monospace, monospace; }
        .fi-bench { font-size:11px; color: var(--fi-text-muted); margin-top:2px; opacity:.85; }
        .fi-note { margin-top:16px; font-size:11px; color: #64748b; padding:0 4px; }
        .dark .fi-note { color:#94a3b8; }
    </style>

    @if (! empty($indicators))
        @foreach ($indicators as $group)
            <div class="fi-card">
                <div class="fi-head" style="background:{{ $group['color'] }};">
                    <div style="font-size:11px; opacity:.85; text-transform:uppercase; letter-spacing:.05em; font-weight:700;">Indicadores</div>
                    <div style="font-size:18px; font-weight:800;">{{ $group['title'] }}</div>
                    <div style="font-size:12px; opacity:.9; font-weight:500;">{{ $group['description'] }}</div>
                </div>

                <div class="fi-grid">
                    @foreach ($group['items'] as $item)
                        @php $st = $item['status'] ?? 'neutral'; @endphp
                        <div class="fi-item">
                            <div class="fi-item-top">
                                <div class="fi-item-name">{{ $item['name'] }}</div>
                                <span class="fi-pill fi-pill-{{ $st }}">{{ $statusLabel[$st] ?? 'Info' }}</span>
                            </div>
                            <div class="fi-value fi-value-{{ $st }}">
                                {{ $fmt($item['value'], $item['format']) }}
                            </div>
                            <div class="fi-formula">{{ $item['formula'] }}</div>
                            <div class="fi-bench">📏 {{ $item['benchmark'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="fi-note">
            * El balance se toma a la fecha "hasta"; los indicadores de flujo (ventas, utilidad, costo) usan el rango completo. Cartera = saldo cuentas 13xx; Inventarios = 14xx; Gastos financieros = 5305. Los umbrales de "saludable / atención / crítico" son referencias generales — ajústalos a tu sector y al criterio de tu contador.
        </div>
    @endif
</x-filament-panels::page>
