@php
    $fmt = fn ($n) => (float) $n == 0.0 ? '—' : '$ ' . number_format((float) $n, 0, ',', '.');
    $corteLabel = \Carbon\Carbon::parse($corte ?? now())->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div class="rep-toolbar">
        <a href="{{ $this->getExportUrl() }}" class="rep-btn rep-btn-excel" target="_blank" rel="noopener">
            📊 Descargar Excel
        </a>
        <button type="button" class="rep-btn rep-btn-print" onclick="window.print()">
            🖨️ Imprimir / Guardar PDF
        </button>
    </div>

    <div class="rep-print-area">
        <div class="ag-card">
            <div class="ag-header">
                <div>
                    <div class="ag-title">Cartera por edades</div>
                    <div class="ag-sub">Corte al {{ $corteLabel }}</div>
                </div>
                <div class="ag-total-head">
                    <div class="ag-total-label">Cartera total</div>
                    <div class="ag-total-value">{{ $fmt($totales['total'] ?? 0) }}</div>
                </div>
            </div>

            @if ($filas->isEmpty())
                <div class="ag-vacio">
                    No hay cartera pendiente a esta fecha.
                </div>
            @else
                <div class="ag-scroll">
                    <table class="ag-table">
                        <thead>
                            <tr>
                                <th class="ag-left">Cliente</th>
                                <th class="ag-left">Documento</th>
                                <th class="ag-center">Fact.</th>
                                <th class="ag-center">Vence</th>
                                <th class="ag-center">Mora</th>
                                @foreach ($tramos as $tramo)
                                    <th class="ag-right ag-tramo-{{ $tramo['clave'] }}">{{ $tramo['rotulo'] }}</th>
                                @endforeach
                                <th class="ag-right ag-col-total">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($filas as $fila)
                                <tr>
                                    <td class="ag-left ag-nombre">
                                        {{ $fila['nombre'] }}
                                        @if ($fila['telefono'])
                                            <span class="ag-tel">{{ $fila['telefono'] }}</span>
                                        @endif
                                    </td>
                                    <td class="ag-left ag-mono">{{ $fila['documento'] ?: '—' }}</td>
                                    <td class="ag-center ag-mono">{{ $fila['facturas'] }}</td>
                                    <td class="ag-center ag-mono">{{ $fila['vence_proxima'] ?? '—' }}</td>
                                    <td class="ag-center">
                                        @if ($fila['dias_max'] > 0)
                                            <span class="ag-badge {{ $fila['dias_max'] > 90 ? 'ag-badge-grave' : ($fila['dias_max'] > 30 ? 'ag-badge-medio' : 'ag-badge-leve') }}">
                                                {{ $fila['dias_max'] }} d
                                            </span>
                                        @else
                                            <span class="ag-badge ag-badge-ok">Al día</span>
                                        @endif
                                    </td>
                                    @foreach ($tramos as $tramo)
                                        <td class="ag-right ag-mono ag-tramo-{{ $tramo['clave'] }}">
                                            {{ $fmt($fila[$tramo['clave']]) }}
                                        </td>
                                    @endforeach
                                    <td class="ag-right ag-mono ag-col-total">{{ $fmt($fila['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="ag-left" colspan="5">
                                    {{ $filas->count() }} {{ $filas->count() === 1 ? 'cliente' : 'clientes' }}
                                </td>
                                @foreach ($tramos as $tramo)
                                    <td class="ag-right ag-mono">{{ $fmt($totales[$tramo['clave']] ?? 0) }}</td>
                                @endforeach
                                <td class="ag-right ag-mono ag-col-total">{{ $fmt($totales['total'] ?? 0) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <style>
        .rep-toolbar { display:flex; gap:8px; margin-top:14px; margin-bottom:8px; flex-wrap:wrap; }
        .rep-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; border:0; }
        .rep-btn-excel { background:#16a34a; color:#fff; }
        .rep-btn-excel:hover { background:#15803d; }
        .rep-btn-print { background:#475569; color:#fff; }
        .rep-btn-print:hover { background:#334155; }

        .ag-card {
            --ag-bg:#ffffff; --ag-text:#0f172a; --ag-muted:#64748b; --ag-border:#e5e7eb;
            --ag-head:#0f172a; --ag-zebra:#f8fafc; --ag-foot:#f1f5f9;
            background:var(--ag-bg); color:var(--ag-text);
            border:1px solid var(--ag-border); border-radius:12px; overflow:hidden; margin-top:8px;
        }
        .dark .ag-card {
            --ag-bg:#1e293b; --ag-text:#e2e8f0; --ag-muted:#94a3b8; --ag-border:#334155;
            --ag-head:#0b1322; --ag-zebra:#182236; --ag-foot:#0f172a;
        }

        .ag-header { background:var(--ag-head); color:#fff; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .ag-title { font-weight:800; font-size:16px; }
        .ag-sub { font-size:12.5px; opacity:.75; margin-top:2px; }
        .ag-total-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; opacity:.75; }
        .ag-total-value { font-weight:800; font-size:20px; }

        .ag-scroll { overflow-x:auto; }
        .ag-table { width:100%; border-collapse:collapse; font-size:13px; }
        .ag-table th, .ag-table td { padding:9px 12px; border-bottom:1px solid var(--ag-border); white-space:nowrap; }
        .ag-table thead th { background:var(--ag-zebra); font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--ag-muted); font-weight:700; }
        .ag-table tbody tr:nth-child(even) { background:var(--ag-zebra); }
        .ag-left { text-align:left; }
        .ag-right { text-align:right; }
        .ag-center { text-align:center; }
        .ag-mono { font-variant-numeric:tabular-nums; }
        .ag-nombre { font-weight:600; white-space:normal; min-width:200px; }
        .ag-tel { display:block; font-size:11.5px; color:var(--ag-muted); font-weight:400; }
        .ag-col-total { font-weight:800; }

        /* Lo más vencido resalta: es lo que hay que cobrar primero. */
        .ag-tramo-d61_90 { color:#c2410c; }
        .ag-tramo-d90_mas { color:#b91c1c; font-weight:700; }
        .dark .ag-tramo-d61_90 { color:#fdba74; }
        .dark .ag-tramo-d90_mas { color:#fca5a5; }

        .ag-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:700; }
        .ag-badge-ok { background:#dcfce7; color:#166534; }
        .ag-badge-leve { background:#fef9c3; color:#854d0e; }
        .ag-badge-medio { background:#ffedd5; color:#9a3412; }
        .ag-badge-grave { background:#fee2e2; color:#991b1b; }
        .dark .ag-badge-ok { background:#14532d; color:#bbf7d0; }
        .dark .ag-badge-leve { background:#422006; color:#fde68a; }
        .dark .ag-badge-medio { background:#431407; color:#fed7aa; }
        .dark .ag-badge-grave { background:#450a0a; color:#fecaca; }

        .ag-table tfoot td { background:var(--ag-foot); font-weight:800; border-top:2px solid var(--ag-border); border-bottom:0; }
        .ag-vacio { padding:32px 18px; text-align:center; color:var(--ag-muted); font-size:14px; }

        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body * { visibility: hidden !important; }
            .rep-print-area, .rep-print-area * { visibility: visible !important; }
            .rep-print-area { position:absolute !important; left:0 !important; top:0 !important; width:100% !important; }
            .ag-card { --ag-bg:#fff; --ag-text:#000; --ag-muted:#444; --ag-border:#ccc; --ag-zebra:#f5f5f5; --ag-foot:#eee; border:1px solid #ccc !important; }
            .ag-header { background:#fff !important; color:#000 !important; border-bottom:2px solid #000; }
            .ag-table tr { page-break-inside: avoid; }
        }
    </style>
</x-filament-panels::page>
