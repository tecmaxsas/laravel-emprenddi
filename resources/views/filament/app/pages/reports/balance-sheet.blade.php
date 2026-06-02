@php
    $fmt = fn ($n) => '$ ' . number_format((float) $n, 0, ',', '.');
    $asOf = $data['as_of'] ?? null;
    $assets = $data['assets'] ?? null;
    $liab = $data['liabilities'] ?? null;
    $equity = $data['equity'] ?? null;
    $asOfLabel = $asOf ? \Carbon\Carbon::parse($asOf)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : '';

    // Helper para imprimir una fila de cuenta detalle
    $detailRow = function ($account) use ($fmt) {
        return '<tr style="background:#fff;">'
            .'<td style="padding:5px 18px 5px 30px; color:#4b5563; font-size:12.5px; font-weight:500;">'.e($account['code']).' · '.e($account['name']).'</td>'
            .'<td style="padding:5px 18px; color:#4b5563; text-align:right; font-family:ui-monospace, monospace; font-size:12.5px; white-space:nowrap;">'.$fmt($account['balance']).'</td>'
            .'</tr>';
    };
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($assets)
        <div style="margin-top:16px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
            {{-- Header --}}
            <div style="padding:14px 18px; background:#0f172a; color:#fff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div>
                    <div style="font-size:11px; opacity:.75; text-transform:uppercase; letter-spacing:.05em; font-weight:700;">Estado de Situación Financiera</div>
                    <div style="font-size:18px; font-weight:800;">Al {{ $asOfLabel }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; opacity:.75; font-weight:600;">Total Activos</div>
                    <div style="font-size:22px; font-weight:900; color:#22c55e;">{{ $fmt($assets['total']) }}</div>
                </div>
            </div>

            {{-- Tabla en 2 columnas: Activos | Pasivo + Patrimonio --}}
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0;">

                {{-- ACTIVOS --}}
                <div style="border-right:1px solid #e5e7eb;">
                    <div style="background:#22c55e; color:#fff; padding:10px 18px; font-weight:800; font-size:14px; letter-spacing:.04em; text-transform:uppercase;">
                        Activos
                    </div>
                    <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                        <tr style="background:#f0fdf4;">
                            <td style="padding:9px 18px; font-weight:800; color:#166534;">ACTIVO CORRIENTE</td>
                            <td style="padding:9px 18px; font-weight:800; color:#166534; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($assets['current_total']) }}</td>
                        </tr>
                        @foreach ($assets['current'] as $a)
                            {!! $detailRow($a) !!}
                        @endforeach
                        <tr style="background:#f0fdf4;">
                            <td style="padding:9px 18px; font-weight:800; color:#166534;">ACTIVO NO CORRIENTE</td>
                            <td style="padding:9px 18px; font-weight:800; color:#166534; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($assets['non_current_total']) }}</td>
                        </tr>
                        @foreach ($assets['non_current'] as $a)
                            {!! $detailRow($a) !!}
                        @endforeach
                        <tr style="background:#16a34a; color:#fff;">
                            <td style="padding:11px 18px; font-weight:900; font-size:14px;">TOTAL ACTIVOS</td>
                            <td style="padding:11px 18px; font-weight:900; text-align:right; font-family:ui-monospace, monospace; font-size:14px;">{{ $fmt($assets['total']) }}</td>
                        </tr>
                    </table>
                </div>

                {{-- PASIVOS + PATRIMONIO --}}
                <div>
                    <div style="background:#0ea5e9; color:#fff; padding:10px 18px; font-weight:800; font-size:14px; letter-spacing:.04em; text-transform:uppercase;">
                        Pasivos y Patrimonio
                    </div>
                    <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                        <tr style="background:#eff6ff;">
                            <td style="padding:9px 18px; font-weight:800; color:#1e40af;">PASIVO CORRIENTE</td>
                            <td style="padding:9px 18px; font-weight:800; color:#1e40af; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($liab['current_total']) }}</td>
                        </tr>
                        @foreach ($liab['current'] as $a)
                            {!! $detailRow($a) !!}
                        @endforeach
                        <tr style="background:#eff6ff;">
                            <td style="padding:9px 18px; font-weight:800; color:#1e40af;">PASIVO NO CORRIENTE</td>
                            <td style="padding:9px 18px; font-weight:800; color:#1e40af; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($liab['non_current_total']) }}</td>
                        </tr>
                        @foreach ($liab['non_current'] as $a)
                            {!! $detailRow($a) !!}
                        @endforeach
                        <tr style="background:#0284c7; color:#fff;">
                            <td style="padding:9px 18px; font-weight:800;">TOTAL PASIVO</td>
                            <td style="padding:9px 18px; font-weight:800; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($liab['total']) }}</td>
                        </tr>

                        <tr style="background:#faf5ff;">
                            <td style="padding:9px 18px; font-weight:800; color:#6b21a8;">PATRIMONIO</td>
                            <td style="padding:9px 18px; font-weight:800; color:#6b21a8; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($equity['total']) }}</td>
                        </tr>
                        @foreach ($equity['accounts'] as $a)
                            {!! $detailRow($a) !!}
                        @endforeach
                        <tr style="background:#fff;">
                            <td style="padding:5px 18px 5px 30px; color:#4b5563; font-size:12.5px; font-weight:500; font-style:italic;">+ Resultado del ejercicio (calculado)</td>
                            <td style="padding:5px 18px; color:#4b5563; text-align:right; font-family:ui-monospace, monospace; font-size:12.5px; font-style:italic; white-space:nowrap;">{{ $fmt($equity['year_result']) }}</td>
                        </tr>
                        <tr style="background:#9333ea; color:#fff;">
                            <td style="padding:9px 18px; font-weight:800;">TOTAL PATRIMONIO</td>
                            <td style="padding:9px 18px; font-weight:800; text-align:right; font-family:ui-monospace, monospace;">{{ $fmt($equity['total']) }}</td>
                        </tr>

                        <tr style="background:#0f172a; color:#fff;">
                            <td style="padding:11px 18px; font-weight:900; font-size:14px;">TOTAL PASIVO + PATRIMONIO</td>
                            <td style="padding:11px 18px; font-weight:900; text-align:right; font-family:ui-monospace, monospace; font-size:14px;">{{ $fmt($data['liab_and_equity_total']) }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Validación: la ecuación contable debe cuadrar --}}
            @php $diff = $data['difference'] ?? 0; @endphp
            <div style="padding:10px 18px; background:{{ abs($diff) < 1 ? '#f0fdf4' : '#fef2f2' }}; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; font-size:13px;">
                <span style="font-weight:600; color:{{ abs($diff) < 1 ? '#166534' : '#991b1b' }};">
                    {{ abs($diff) < 1 ? '✓ La ecuación contable cuadra (Activos = Pasivos + Patrimonio).' : '⚠ Diferencia: '.$fmt($diff).'. Revisa asientos sin contrapartida.' }}
                </span>
            </div>
        </div>

        <div style="margin-top:10px; font-size:11px; color:#6b7280; padding:0 4px;">
            * El "Resultado del ejercicio" se calcula desde el 1 de enero del año de corte hasta la fecha seleccionada — se suma al patrimonio para que la ecuación contable cuadre durante el ejercicio en curso (aún sin cierre contable formal). Se incluyen solo cuentas con saldo (nivel ≤ 4 del PUC).
        </div>
    @endif
</x-filament-panels::page>
