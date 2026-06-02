@php
    $fmt = fn ($n) => '$ ' . number_format((float) $n, 0, ',', '.');
    $asOf = $data['as_of'] ?? null;
    $assets = $data['assets'] ?? null;
    $liab = $data['liabilities'] ?? null;
    $equity = $data['equity'] ?? null;
    $asOfLabel = $asOf ? \Carbon\Carbon::parse($asOf)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : '';
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <style>
        .bs-card {
            --bs-bg: #ffffff; --bs-text: #0f172a; --bs-text-muted: #64748b; --bs-border: #e5e7eb;
            --bs-detail-bg: transparent; --bs-detail-text: #475569;
            --bs-asset-bg: #f0fdf4; --bs-asset-text: #166534; --bs-asset-accent: #16a34a;
            --bs-liab-bg: #eff6ff; --bs-liab-text: #1e40af; --bs-liab-accent: #0284c7;
            --bs-equity-bg: #faf5ff; --bs-equity-text: #6b21a8; --bs-equity-accent: #9333ea;
            --bs-grand-bg: #0f172a;
            background: var(--bs-bg); color: var(--bs-text);
            border: 1px solid var(--bs-border); border-radius: 12px;
            overflow: hidden; margin-top: 16px;
        }
        .dark .bs-card {
            --bs-bg: #1e293b; --bs-text: #e2e8f0; --bs-text-muted: #94a3b8; --bs-border: #334155;
            --bs-detail-bg: transparent; --bs-detail-text: #cbd5e1;
            --bs-asset-bg: #14532d; --bs-asset-text: #bbf7d0; --bs-asset-accent: #16a34a;
            --bs-liab-bg: #172554; --bs-liab-text: #bfdbfe; --bs-liab-accent: #0284c7;
            --bs-equity-bg: #3b0764; --bs-equity-text: #e9d5ff; --bs-equity-accent: #9333ea;
            --bs-grand-bg: #0b1322;
        }
        .bs-header { background:#0f172a; color:#fff; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
        .bs-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; }
        @media (max-width: 900px) { .bs-grid { grid-template-columns:1fr; } }
        .bs-grid > div:first-child { border-right:1px solid var(--bs-border); }
        @media (max-width: 900px) {
            .bs-grid > div:first-child { border-right:0; border-bottom:1px solid var(--bs-border); }
        }
        .bs-side-title { padding:10px 18px; font-weight:800; font-size:14px; letter-spacing:.04em; text-transform:uppercase; color:#fff; }
        .bs-side-assets { background: var(--bs-asset-accent); }
        .bs-side-liab { background: var(--bs-liab-accent); }
        .bs-table { width:100%; border-collapse:collapse; font-size:13.5px; }
        .bs-table tr { border-top:1px solid var(--bs-border); }
        .bs-sec-asset td { background: var(--bs-asset-bg); color: var(--bs-asset-text); font-weight:800; padding:9px 18px; }
        .bs-sec-liab td { background: var(--bs-liab-bg); color: var(--bs-liab-text); font-weight:800; padding:9px 18px; }
        .bs-sec-equity td { background: var(--bs-equity-bg); color: var(--bs-equity-text); font-weight:800; padding:9px 18px; }
        .bs-sub-asset td { background: var(--bs-asset-accent); color:#fff; font-weight:800; padding:9px 18px; font-size:13.5px; }
        .bs-sub-liab td { background: var(--bs-liab-accent); color:#fff; font-weight:800; padding:9px 18px; font-size:13.5px; }
        .bs-sub-equity td { background: var(--bs-equity-accent); color:#fff; font-weight:800; padding:9px 18px; font-size:13.5px; }
        .bs-detail td { background: var(--bs-detail-bg); color: var(--bs-detail-text); font-size:12.5px; font-weight:500; padding:5px 18px 5px 30px; }
        .bs-detail-italic td { font-style: italic; }
        .bs-grand td { background: var(--bs-grand-bg); color:#fff; font-weight:900; padding:11px 18px; font-size:14px; }
        .bs-num { text-align:right; font-family:ui-monospace, "SF Mono", Menlo, Consolas, monospace; white-space:nowrap; }
        .bs-validation {
            padding:10px 18px; border-top:1px solid var(--bs-border);
            display:flex; justify-content:space-between; align-items:center; font-size:13px;
        }
        .bs-valid-ok { background:#dcfce7; color:#166534; }
        .dark .bs-valid-ok { background:#14532d; color:#bbf7d0; }
        .bs-valid-err { background:#fee2e2; color:#991b1b; }
        .dark .bs-valid-err { background:#7f1d1d; color:#fecaca; }
        .bs-note { margin-top:10px; font-size:11px; color: var(--bs-text-muted); padding:0 4px; }
        .dark .bs-note { color:#94a3b8; }
        .bs-toolbar { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
        .bs-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; border:0; }
        .bs-btn-excel { background:#16a34a; color:#fff; }
        .bs-btn-excel:hover { background:#15803d; }
        .bs-btn-print { background:#475569; color:#fff; }
        .bs-btn-print:hover { background:#334155; }

        @media print {
            @page { size: A4; margin: 12mm; }
            body * { visibility: hidden !important; }
            .print-area, .print-area * { visibility: visible !important; }
            .print-area {
                position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important;
                box-shadow: none !important; border: 1px solid #999 !important;
                background: #fff !important; color: #000 !important; margin-top: 0 !important;
            }
            .print-area .bs-header { background:#0f172a !important; color:#fff !important; }
            .print-area .bs-sec-asset td { background:#f0fdf4 !important; color:#166534 !important; }
            .print-area .bs-sec-liab td { background:#eff6ff !important; color:#1e40af !important; }
            .print-area .bs-sec-equity td { background:#faf5ff !important; color:#6b21a8 !important; }
            .print-area .bs-sub-asset td { background:#16a34a !important; color:#fff !important; }
            .print-area .bs-sub-liab td { background:#0284c7 !important; color:#fff !important; }
            .print-area .bs-sub-equity td { background:#9333ea !important; color:#fff !important; }
            .print-area .bs-detail td { background:#fff !important; color:#475569 !important; }
            .print-area .bs-grand td { background:#0f172a !important; color:#fff !important; }
            .print-area tr { page-break-inside: avoid; }
        }
    </style>

    @php
        $detailRow = function ($account) use ($fmt) {
            return '<tr class="bs-detail">'
                .'<td>'.e($account['code']).' · '.e($account['name']).'</td>'
                .'<td class="bs-num">'.$fmt($account['balance']).'</td>'
                .'</tr>';
        };
    @endphp

    @if ($assets)
        <div class="bs-toolbar">
            <a href="{{ route('reports.export.balance_sheet', ['as_of' => $filters['as_of'] ?? null]) }}"
               class="bs-btn bs-btn-excel" target="_blank" rel="noopener">
                📊 Descargar Excel
            </a>
            <button type="button" class="bs-btn bs-btn-print" onclick="window.print()">
                🖨️ Imprimir / Guardar PDF
            </button>
        </div>

        <div class="bs-card print-area">
            <div class="bs-header">
                <div>
                    <div style="font-size:11px; opacity:.75; text-transform:uppercase; letter-spacing:.05em; font-weight:700;">Estado de Situación Financiera</div>
                    <div style="font-size:18px; font-weight:800;">Al {{ $asOfLabel }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; opacity:.75; font-weight:600;">Total Activos</div>
                    <div style="font-size:22px; font-weight:900; color:#22c55e;">{{ $fmt($assets['total']) }}</div>
                </div>
            </div>

            <div class="bs-grid">
                {{-- ACTIVOS --}}
                <div>
                    <div class="bs-side-title bs-side-assets">Activos</div>
                    <table class="bs-table">
                        <tr class="bs-sec-asset"><td>ACTIVO CORRIENTE</td><td class="bs-num">{{ $fmt($assets['current_total']) }}</td></tr>
                        @foreach ($assets['current'] as $a) {!! $detailRow($a) !!} @endforeach
                        <tr class="bs-sec-asset"><td>ACTIVO NO CORRIENTE</td><td class="bs-num">{{ $fmt($assets['non_current_total']) }}</td></tr>
                        @foreach ($assets['non_current'] as $a) {!! $detailRow($a) !!} @endforeach
                        <tr class="bs-sub-asset"><td>TOTAL ACTIVOS</td><td class="bs-num">{{ $fmt($assets['total']) }}</td></tr>
                    </table>
                </div>

                {{-- PASIVOS + PATRIMONIO --}}
                <div>
                    <div class="bs-side-title bs-side-liab">Pasivos y Patrimonio</div>
                    <table class="bs-table">
                        <tr class="bs-sec-liab"><td>PASIVO CORRIENTE</td><td class="bs-num">{{ $fmt($liab['current_total']) }}</td></tr>
                        @foreach ($liab['current'] as $a) {!! $detailRow($a) !!} @endforeach
                        <tr class="bs-sec-liab"><td>PASIVO NO CORRIENTE</td><td class="bs-num">{{ $fmt($liab['non_current_total']) }}</td></tr>
                        @foreach ($liab['non_current'] as $a) {!! $detailRow($a) !!} @endforeach
                        <tr class="bs-sub-liab"><td>TOTAL PASIVO</td><td class="bs-num">{{ $fmt($liab['total']) }}</td></tr>

                        <tr class="bs-sec-equity"><td>PATRIMONIO</td><td class="bs-num">{{ $fmt($equity['total']) }}</td></tr>
                        @foreach ($equity['accounts'] as $a) {!! $detailRow($a) !!} @endforeach
                        <tr class="bs-detail bs-detail-italic">
                            <td>+ Resultado del ejercicio (calculado)</td>
                            <td class="bs-num">{{ $fmt($equity['year_result']) }}</td>
                        </tr>
                        <tr class="bs-sub-equity"><td>TOTAL PATRIMONIO</td><td class="bs-num">{{ $fmt($equity['total']) }}</td></tr>

                        <tr class="bs-grand"><td>TOTAL PASIVO + PATRIMONIO</td><td class="bs-num">{{ $fmt($data['liab_and_equity_total']) }}</td></tr>
                    </table>
                </div>
            </div>

            @php $diff = $data['difference'] ?? 0; @endphp
            <div class="bs-validation {{ abs($diff) < 1 ? 'bs-valid-ok' : 'bs-valid-err' }}">
                <span style="font-weight:600;">
                    {{ abs($diff) < 1 ? '✓ La ecuación contable cuadra (Activos = Pasivos + Patrimonio).' : '⚠ Diferencia: '.$fmt($diff).'. Revisa asientos sin contrapartida.' }}
                </span>
            </div>
        </div>

        <div class="bs-note">
            * El "Resultado del ejercicio" se calcula desde el 1 de enero del año de corte hasta la fecha seleccionada — se suma al patrimonio para que la ecuación contable cuadre durante el ejercicio en curso (aún sin cierre contable formal). Se incluyen solo cuentas con saldo (nivel ≤ 4 del PUC).
        </div>
    @endif
</x-filament-panels::page>
