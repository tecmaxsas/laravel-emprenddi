@php
    $t = $data['totals'] ?? null;
    $s = $data['sections'] ?? [];
    $fmt = fn ($n) => '$ ' . number_format((float) $n, 0, ',', '.');
    $neg = fn ($n) => '(' . $fmt(abs((float) $n)) . ')';
    $pct = function ($n, $base) {
        if ($base <= 0) return '';
        $v = $n / $base * 100;
        if (abs($v) > 999) return '';
        return number_format($v, 1, ',', '.') . '%';
    };

    $periodLabel = isset($data['period'])
        ? \Carbon\Carbon::parse($data['period']['from'])->locale('es')->isoFormat('D MMM YYYY')
            .' — '.\Carbon\Carbon::parse($data['period']['to'])->locale('es')->isoFormat('D MMM YYYY')
        : '';

    $base = $t['revenue_operating'] ?? 0;
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <style>
        .fs-card {
            --fs-bg: #ffffff; --fs-text: #0f172a; --fs-text-muted: #64748b; --fs-border: #e5e7eb;
            --fs-section-bg: #f1f5f9; --fs-section-text: #0f172a;
            --fs-detail-text: #475569;
            --fs-total-bg: #0f172a; --fs-total-text: #ffffff;
            background: var(--fs-bg); color: var(--fs-text);
            border: 1px solid var(--fs-border); border-radius: 12px;
            overflow: hidden; margin-top: 16px;
        }
        .dark .fs-card {
            --fs-bg: #1e293b; --fs-text: #e2e8f0; --fs-text-muted: #94a3b8; --fs-border: #334155;
            --fs-section-bg: #273449; --fs-section-text: #e2e8f0;
            --fs-detail-text: #cbd5e1;
            --fs-total-bg: #0b1322; --fs-total-text: #ffffff;
        }
        .fs-header { background:#0f172a; color:#fff; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
        .fs-table { width:100%; border-collapse:collapse; font-size:14px; }
        .fs-table tr { border-top:1px solid var(--fs-border); }
        .fs-section td { background: var(--fs-section-bg); color: var(--fs-section-text); font-weight:800; padding:10px 18px; }
        .fs-section-sep td { border-top:2px solid var(--fs-border) !important; }
        .fs-bold td { color: var(--fs-text); font-weight:700; padding:9px 18px; }
        .fs-detail td { color: var(--fs-detail-text); font-weight:500; padding:5px 18px 5px 38px; font-size:12.5px; }
        .fs-total td { background: var(--fs-total-bg); color: var(--fs-total-text); font-weight:900; padding:12px 18px; font-size:15px; }
        .fs-num { text-align:right; font-family:ui-monospace, "SF Mono", Menlo, Consolas, monospace; white-space:nowrap; }
        .fs-pct { text-align:right; font-family:ui-monospace, monospace; width:78px; white-space:nowrap; font-size:11px; color: var(--fs-text-muted); opacity:.8; }
        .fs-note { margin-top:10px; font-size:11px; color: var(--fs-text-muted); padding:0 4px; }
        .dark .fs-note { color:#94a3b8; }
        .fs-toolbar { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
        .fs-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; border:0; }
        .fs-btn-excel { background:#16a34a; color:#fff; }
        .fs-btn-excel:hover { background:#15803d; }
        .fs-btn-print { background:#475569; color:#fff; }
        .fs-btn-print:hover { background:#334155; }

        @media print {
            @page { size: A4; margin: 12mm; }
            body * { visibility: hidden !important; }
            .print-area, .print-area * { visibility: visible !important; }
            .print-area {
                position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important;
                box-shadow: none !important; border: 1px solid #999 !important;
                background: #fff !important; color: #000 !important; margin-top: 0 !important;
            }
            .print-area .fs-header { background:#0f172a !important; color:#fff !important; }
            .print-area .fs-section td { background:#f1f5f9 !important; color:#0f172a !important; }
            .print-area .fs-bold td, .print-area .fs-detail td { background:#fff !important; color:#000 !important; }
            .print-area .fs-total td { background:#0f172a !important; color:#fff !important; }
            .print-area tr { page-break-inside: avoid; }
        }
    </style>

    @if ($t)
        <div class="fs-toolbar">
            <a href="{{ route('reports.export.income_statement', ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) }}"
               class="fs-btn fs-btn-excel" target="_blank" rel="noopener">
                📊 Descargar Excel
            </a>
            <button type="button" class="fs-btn fs-btn-print" onclick="window.print()">
                🖨️ Imprimir / Guardar PDF
            </button>
        </div>

        <div class="fs-card print-area">
            <div class="fs-header">
                <div>
                    <div style="font-size:11px; opacity:.75; text-transform:uppercase; letter-spacing:.05em; font-weight:700;">Estado de Resultados Integral</div>
                    <div style="font-size:18px; font-weight:800;">Período: {{ $periodLabel }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; opacity:.75; font-weight:600;">Resultado del período</div>
                    <div style="font-size:22px; font-weight:900; color:{{ $t['net_result'] >= 0 ? '#22c55e' : '#f87171' }};">{{ $fmt($t['net_result']) }}</div>
                </div>
            </div>

            <table class="fs-table">
                {{-- INGRESOS OPERACIONALES --}}
                <tr class="fs-section">
                    <td>Ingresos operacionales</td>
                    <td class="fs-num">{{ $fmt($t['revenue_operating']) }}</td>
                    <td class="fs-pct">100,0%</td>
                </tr>
                @foreach ($s['revenue_operating'] ?? [] as $a)
                    <tr class="fs-detail">
                        <td>{{ $a['code'] }} · {{ $a['name'] }}</td>
                        <td class="fs-num">{{ $fmt($a['balance']) }}</td>
                        <td class="fs-pct"></td>
                    </tr>
                @endforeach

                {{-- COSTO DE VENTAS --}}
                <tr class="fs-section">
                    <td>(−) Costo de ventas</td>
                    <td class="fs-num">{{ $neg($t['cogs']) }}</td>
                    <td class="fs-pct">{{ $pct(-$t['cogs'], $base) }}</td>
                </tr>
                @foreach ($s['cogs'] ?? [] as $a)
                    <tr class="fs-detail">
                        <td>{{ $a['code'] }} · {{ $a['name'] }}</td>
                        <td class="fs-num">{{ $neg($a['balance']) }}</td>
                        <td class="fs-pct"></td>
                    </tr>
                @endforeach

                {{-- UTILIDAD BRUTA --}}
                <tr class="fs-section fs-section-sep">
                    <td>= Utilidad bruta</td>
                    <td class="fs-num">{{ $fmt($t['gross_profit']) }}</td>
                    <td class="fs-pct">{{ $pct($t['gross_profit'], $base) }}</td>
                </tr>

                {{-- GASTOS OPERACIONALES --}}
                <tr class="fs-bold">
                    <td>(−) Gastos de administración (51)</td>
                    <td class="fs-num">{{ $neg($t['opex_admin']) }}</td>
                    <td class="fs-pct">{{ $pct(-$t['opex_admin'], $base) }}</td>
                </tr>
                @foreach ($s['opex_admin'] ?? [] as $a)
                    <tr class="fs-detail">
                        <td>{{ $a['code'] }} · {{ $a['name'] }}</td>
                        <td class="fs-num">{{ $neg($a['balance']) }}</td>
                        <td class="fs-pct"></td>
                    </tr>
                @endforeach

                <tr class="fs-bold">
                    <td>(−) Gastos de ventas (52)</td>
                    <td class="fs-num">{{ $neg($t['opex_sales']) }}</td>
                    <td class="fs-pct">{{ $pct(-$t['opex_sales'], $base) }}</td>
                </tr>
                @foreach ($s['opex_sales'] ?? [] as $a)
                    <tr class="fs-detail">
                        <td>{{ $a['code'] }} · {{ $a['name'] }}</td>
                        <td class="fs-num">{{ $neg($a['balance']) }}</td>
                        <td class="fs-pct"></td>
                    </tr>
                @endforeach

                {{-- UTILIDAD OPERACIONAL --}}
                <tr class="fs-section fs-section-sep">
                    <td>= Utilidad operacional (EBIT)</td>
                    <td class="fs-num">{{ $fmt($t['operating_result']) }}</td>
                    <td class="fs-pct">{{ $pct($t['operating_result'], $base) }}</td>
                </tr>

                {{-- NO OPERACIONALES --}}
                @if ($t['revenue_non_op'] > 0 || ! empty($s['revenue_non_op']))
                    <tr class="fs-bold">
                        <td>(+) Ingresos no operacionales (42)</td>
                        <td class="fs-num">{{ $fmt($t['revenue_non_op']) }}</td>
                        <td class="fs-pct">{{ $pct($t['revenue_non_op'], $base) }}</td>
                    </tr>
                    @foreach ($s['revenue_non_op'] ?? [] as $a)
                        <tr class="fs-detail"><td>{{ $a['code'] }} · {{ $a['name'] }}</td><td class="fs-num">{{ $fmt($a['balance']) }}</td><td class="fs-pct"></td></tr>
                    @endforeach
                @endif

                @if ($t['opex_non_op'] > 0 || ! empty($s['opex_non_op']))
                    <tr class="fs-bold">
                        <td>(−) Gastos no operacionales (53)</td>
                        <td class="fs-num">{{ $neg($t['opex_non_op']) }}</td>
                        <td class="fs-pct">{{ $pct(-$t['opex_non_op'], $base) }}</td>
                    </tr>
                    @foreach ($s['opex_non_op'] ?? [] as $a)
                        <tr class="fs-detail"><td>{{ $a['code'] }} · {{ $a['name'] }}</td><td class="fs-num">{{ $neg($a['balance']) }}</td><td class="fs-pct"></td></tr>
                    @endforeach
                @endif

                {{-- ANTES DE IMPUESTOS --}}
                <tr class="fs-section fs-section-sep">
                    <td>= Utilidad antes de impuestos</td>
                    <td class="fs-num">{{ $fmt($t['before_tax']) }}</td>
                    <td class="fs-pct">{{ $pct($t['before_tax'], $base) }}</td>
                </tr>

                @if ($t['tax'] > 0 || ! empty($s['tax']))
                    <tr class="fs-bold">
                        <td>(−) Impuesto de renta (54)</td>
                        <td class="fs-num">{{ $neg($t['tax']) }}</td>
                        <td class="fs-pct">{{ $pct(-$t['tax'], $base) }}</td>
                    </tr>
                @endif

                {{-- UTILIDAD NETA --}}
                <tr class="fs-total">
                    <td>UTILIDAD NETA</td>
                    <td class="fs-num">{{ $fmt($t['net_result']) }}</td>
                    <td class="fs-pct" style="opacity:.85;">{{ $pct($t['net_result'], $base) }}</td>
                </tr>

                {{-- ORI + INTEGRAL --}}
                <tr class="fs-bold">
                    <td>(+/-) Otro Resultado Integral (ORI)</td>
                    <td class="fs-num">{{ $fmt($t['ori']) }}</td>
                    <td class="fs-pct"></td>
                </tr>
                <tr class="fs-total">
                    <td>RESULTADO INTEGRAL TOTAL</td>
                    <td class="fs-num">{{ $fmt($t['integral_result']) }}</td>
                    <td class="fs-pct" style="opacity:.85;">{{ $pct($t['integral_result'], $base) }}</td>
                </tr>
            </table>
        </div>

        <div class="fs-note">
            * Análisis vertical: los porcentajes son sobre los ingresos operacionales. Se ocultan cuando salen del rango razonable (±999%). Se muestran cuentas con movimiento del nivel ≤ 4 del PUC. El ORI requiere registros NIIF específicos; se calculará en versiones futuras.
        </div>
    @endif
</x-filament-panels::page>
