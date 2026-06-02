@php
    $t = $data['totals'] ?? null;
    $s = $data['sections'] ?? [];
    $fmt = fn ($n) => '$ ' . number_format((float) $n, 0, ',', '.');
    $pct = fn ($n, $base) => $base > 0 ? number_format($n / $base * 100, 1, ',', '.') . '%' : '—';

    $periodLabel = isset($data['period'])
        ? \Carbon\Carbon::parse($data['period']['from'])->locale('es')->isoFormat('D MMM YYYY')
            .' — '.\Carbon\Carbon::parse($data['period']['to'])->locale('es')->isoFormat('D MMM YYYY')
        : '';
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($t)
        <div style="margin-top:16px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
            <div style="padding:14px 18px; background:#0f172a; color:#fff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div>
                    <div style="font-size:11px; opacity:.75; text-transform:uppercase; letter-spacing:.05em; font-weight:700;">Estado de Resultados Integral</div>
                    <div style="font-size:18px; font-weight:800;">Período: {{ $periodLabel }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; opacity:.75; font-weight:600;">Resultado del período</div>
                    <div style="font-size:22px; font-weight:900; color:{{ $t['net_result'] >= 0 ? '#22c55e' : '#ef4444' }};">
                        {{ $fmt($t['net_result']) }}
                    </div>
                </div>
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                @php
                    $row = function ($label, $value, $opts = []) use ($fmt, $pct, $t) {
                        $bold = $opts['bold'] ?? false;
                        $sub = $opts['sub'] ?? false;
                        $totalRow = $opts['total'] ?? false;
                        $emphasized = $opts['emphasized'] ?? false;
                        $detail = $opts['detail'] ?? false;
                        $showPct = $opts['pct'] ?? true;
                        $sign = $opts['sign'] ?? '';
                        $bg = $totalRow ? '#0f172a' : ($emphasized ? '#eef2ff' : ($detail ? '#fff' : '#f8fafc'));
                        $color = $totalRow ? '#fff' : ($emphasized ? '#3730a3' : '#1f2937');
                        $weight = $totalRow || $bold || $emphasized ? '800' : ($sub ? '600' : '500');
                        $size = $totalRow ? '16px' : ($emphasized ? '15px' : '13.5px');
                        $padding = $detail ? '5px 18px 5px 36px' : ($sub ? '8px 18px 8px 30px' : '10px 18px');
                        return [
                            'label' => $label,
                            'value' => $value,
                            'bg' => $bg,
                            'color' => $color,
                            'weight' => $weight,
                            'size' => $size,
                            'padding' => $padding,
                            'sign' => $sign,
                            'showPct' => $showPct,
                            'pctVal' => $showPct && $t['revenue_operating'] > 0 ? $pct($value, $t['revenue_operating']) : '',
                        ];
                    };

                    $rows = [];

                    // INGRESOS OPERACIONALES
                    $rows[] = $row('Ingresos operacionales', $t['revenue_operating'], ['emphasized' => true]);
                    foreach ($s['revenue_operating'] ?? [] as $a) {
                        $rows[] = $row($a['code'].' · '.$a['name'], $a['balance'], ['detail' => true, 'pct' => false]);
                    }

                    // COSTO DE VENTAS
                    $rows[] = $row('(−) Costo de ventas', -$t['cogs'], ['emphasized' => true, 'sign' => '-']);
                    foreach ($s['cogs'] ?? [] as $a) {
                        $rows[] = $row($a['code'].' · '.$a['name'], -$a['balance'], ['detail' => true, 'pct' => false]);
                    }

                    // UTILIDAD BRUTA
                    $rows[] = $row('= Utilidad bruta', $t['gross_profit'], ['emphasized' => true]);

                    // GASTOS ADMINISTRATIVOS
                    $rows[] = $row('(−) Gastos de administración (51)', -$t['opex_admin'], ['bold' => true, 'sign' => '-']);
                    foreach ($s['opex_admin'] ?? [] as $a) {
                        $rows[] = $row($a['code'].' · '.$a['name'], -$a['balance'], ['detail' => true, 'pct' => false]);
                    }

                    // GASTOS DE VENTAS
                    $rows[] = $row('(−) Gastos de ventas (52)', -$t['opex_sales'], ['bold' => true, 'sign' => '-']);
                    foreach ($s['opex_sales'] ?? [] as $a) {
                        $rows[] = $row($a['code'].' · '.$a['name'], -$a['balance'], ['detail' => true, 'pct' => false]);
                    }

                    // UTILIDAD OPERACIONAL
                    $rows[] = $row('= Utilidad operacional (EBIT)', $t['operating_result'], ['emphasized' => true]);

                    // INGRESOS NO OPERACIONALES
                    if ($t['revenue_non_op'] > 0 || ! empty($s['revenue_non_op'])) {
                        $rows[] = $row('(+) Ingresos no operacionales (42)', $t['revenue_non_op'], ['bold' => true]);
                        foreach ($s['revenue_non_op'] ?? [] as $a) {
                            $rows[] = $row($a['code'].' · '.$a['name'], $a['balance'], ['detail' => true, 'pct' => false]);
                        }
                    }

                    // GASTOS NO OPERACIONALES (incluye financieros)
                    if ($t['opex_non_op'] > 0 || ! empty($s['opex_non_op'])) {
                        $rows[] = $row('(−) Gastos no operacionales (53)', -$t['opex_non_op'], ['bold' => true, 'sign' => '-']);
                        foreach ($s['opex_non_op'] ?? [] as $a) {
                            $rows[] = $row($a['code'].' · '.$a['name'], -$a['balance'], ['detail' => true, 'pct' => false]);
                        }
                    }

                    // UTILIDAD ANTES DE IMPUESTOS
                    $rows[] = $row('= Utilidad antes de impuestos', $t['before_tax'], ['emphasized' => true]);

                    // IMPUESTO DE RENTA
                    if ($t['tax'] > 0 || ! empty($s['tax'])) {
                        $rows[] = $row('(−) Impuesto de renta (54)', -$t['tax'], ['bold' => true, 'sign' => '-']);
                    }

                    // UTILIDAD NETA
                    $rows[] = $row('UTILIDAD NETA', $t['net_result'], ['total' => true]);

                    // ORI + RESULTADO INTEGRAL
                    $rows[] = $row('(+/-) Otro Resultado Integral (ORI)', $t['ori'], ['bold' => true]);
                    $rows[] = $row('RESULTADO INTEGRAL TOTAL', $t['integral_result'], ['total' => true]);
                @endphp

                @foreach ($rows as $r)
                    <tr style="background:{{ $r['bg'] }}; border-top:1px solid #e5e7eb;">
                        <td style="padding:{{ $r['padding'] }}; color:{{ $r['color'] }}; font-weight:{{ $r['weight'] }}; font-size:{{ $r['size'] }};">{{ $r['label'] }}</td>
                        <td style="padding:{{ $r['padding'] }}; color:{{ $r['color'] }}; font-weight:{{ $r['weight'] }}; font-size:{{ $r['size'] }}; text-align:right; font-family:'Inter Mono', ui-monospace, monospace; white-space:nowrap;">
                            {{ $r['sign'] === '-' && $r['value'] !== 0 ? '(' . $fmt(abs($r['value'])) . ')' : $fmt($r['value']) }}
                        </td>
                        <td style="padding:{{ $r['padding'] }}; color:{{ $r['color'] }}; opacity:{{ $r['showPct'] ? '.65' : '0' }}; font-size:11px; text-align:right; font-family:'Inter Mono', ui-monospace, monospace; width:80px; white-space:nowrap;">
                            {{ $r['pctVal'] }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>

        <div style="margin-top:10px; font-size:11px; color:#6b7280; padding:0 4px;">
            * Los porcentajes son sobre los ingresos operacionales (estructura vertical del estado). Se incluyen solo cuentas con movimiento en el período (nivel ≤ 4 del PUC). El ORI requiere registros NIIF específicos; en versiones futuras se calculará automáticamente.
        </div>
    @endif
</x-filament-panels::page>
