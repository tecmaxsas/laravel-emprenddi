@php
    $fmt = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
    $fmtMin = fn ($n) => number_format((int) $n, 0, ',', '.').' min';
@endphp

<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    <div class="rep-toolbar">
        <a href="{{ $this->getExportUrl() }}" target="_blank" rel="noopener" class="rep-btn rep-btn-excel">
            📊 Descargar Excel
        </a>
        <button type="button" class="rep-btn rep-btn-print" onclick="window.print()">
            🖨️ Imprimir / Guardar PDF
        </button>
    </div>

    <div class="rep-print-area">
        {{-- KPIs --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-top:14px;">
            <div style="background:#16a34a; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Ingresos</div>
                <div style="font-size:24px; font-weight:900; margin-top:2px;">{{ $fmt($totals['revenue']) }}</div>
            </div>
            <div style="background:#0f172a; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.7; text-transform:uppercase; font-weight:700;">Sesiones</div>
                <div style="font-size:24px; font-weight:900;">{{ number_format($totals['sessions'], 0, ',', '.') }}</div>
            </div>
            <div style="background:#475569; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Ticket promedio</div>
                <div style="font-size:24px; font-weight:900;">{{ $fmt($totals['avg_per_session']) }}</div>
            </div>
            <div style="background:#6366f1; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Tiempo vendido</div>
                <div style="font-size:24px; font-weight:900;">{{ $fmtMin($totals['minutes']) }}</div>
            </div>
            <div style="background:#f59e0b; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Ticket perdido</div>
                <div style="font-size:24px; font-weight:900;">{{ number_format($totals['lost_tickets'], 0, ',', '.') }}</div>
            </div>
            <div style="background:#3b82f6; color:#fff; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; opacity:.85; text-transform:uppercase; font-weight:700;">Mensualidades</div>
                <div style="font-size:24px; font-weight:900;">{{ number_format($totals['memberships'], 0, ',', '.') }}</div>
            </div>
        </div>

        @if ($rows->isEmpty())
            <div style="margin-top:18px; padding:30px; text-align:center; color:#6b7280; background:#f9fafb; border-radius:10px;">
                Sin sesiones cerradas en el período seleccionado.
            </div>
        @else
            <div style="margin-top:14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead style="background:#f3f4f6;">
                        <tr>
                            <th style="padding:10px 14px; text-align:left;">Día</th>
                            <th style="padding:10px 14px; text-align:right;">Sesiones</th>
                            <th style="padding:10px 14px; text-align:right;">Minutos</th>
                            <th style="padding:10px 14px; text-align:right;">Ingresos</th>
                            <th style="padding:10px 14px; text-align:right;">Ticket promedio</th>
                            <th style="padding:10px 14px; text-align:right;">Mensualidades</th>
                            <th style="padding:10px 14px; text-align:right;">Ticket perdido</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php $avg = $r->sessions > 0 ? $r->revenue / $r->sessions : 0; @endphp
                            <tr style="border-top:1px solid #f1f5f9;">
                                <td style="padding:8px 14px; font-family:ui-monospace, monospace;">{{ $r->day }}</td>
                                <td style="padding:8px 14px; text-align:right;">{{ number_format($r->sessions, 0, ',', '.') }}</td>
                                <td style="padding:8px 14px; text-align:right;">{{ number_format($r->minutes, 0, ',', '.') }}</td>
                                <td style="padding:8px 14px; text-align:right; font-weight:700;">{{ $fmt($r->revenue) }}</td>
                                <td style="padding:8px 14px; text-align:right;">{{ $fmt($avg) }}</td>
                                <td style="padding:8px 14px; text-align:right;">{{ number_format($r->memberships, 0, ',', '.') }}</td>
                                <td style="padding:8px 14px; text-align:right; color:#92400e;">{{ number_format($r->lost_tickets, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot style="background:#f3f4f6;">
                        <tr>
                            <th style="padding:10px 14px; text-align:left;">Total</th>
                            <th style="padding:10px 14px; text-align:right;">{{ number_format($totals['sessions'], 0, ',', '.') }}</th>
                            <th style="padding:10px 14px; text-align:right;">{{ number_format($totals['minutes'], 0, ',', '.') }}</th>
                            <th style="padding:10px 14px; text-align:right;">{{ $fmt($totals['revenue']) }}</th>
                            <th style="padding:10px 14px; text-align:right;">{{ $fmt($totals['avg_per_session']) }}</th>
                            <th style="padding:10px 14px; text-align:right;">{{ number_format($totals['memberships'], 0, ',', '.') }}</th>
                            <th style="padding:10px 14px; text-align:right;">{{ number_format($totals['lost_tickets'], 0, ',', '.') }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <style>
        .rep-toolbar { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
        .rep-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; border:0; }
        .rep-btn-excel { background:#16a34a; color:#fff; }
        .rep-btn-excel:hover { background:#15803d; }
        .rep-btn-print { background:#475569; color:#fff; }
        .rep-btn-print:hover { background:#334155; }

        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body * { visibility: hidden !important; }
            .rep-print-area, .rep-print-area * { visibility: visible !important; }
            .rep-print-area {
                position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important;
                background: #fff !important; color: #000 !important;
            }
            .rep-toolbar { display: none !important; }
        }
    </style>
</x-filament-panels::page>
