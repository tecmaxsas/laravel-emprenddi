@php
    use App\Support\ClockFormat;
    use App\Support\PaymentMethodOptions;

    $isThermal = in_array($paperSize, ['pos_58', 'pos_80'], true);
    $widthMm = match ($paperSize) {
        'pos_58' => '58mm',
        'pos_80' => '80mm',
        'letter_half' => '108mm',
        'a5' => '148mm',
        default => '80mm',
    };

    $empresa = $sesion->company;
    $sede = $sesion->location;
    $plata = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');

    $cerrada = $sesion->status !== 'open';

    // Lo CONTADO y la diferencia son del cierre: si la caja sigue abierta
    // todavía no existen, y mostrarlos en cero haría creer que cuadró.
    $contado = $cerrada ? (float) $sesion->closing_counted : null;
    $esperado = $cerrada
        ? (float) ($sesion->closing_expected ?: $resumen['expected_cash'])
        : (float) $resumen['expected_cash'];
    $diferencia = $contado !== null ? round($contado - $esperado, 2) : null;

    // El desglose por método es el punto del tiquete: la plata del cajón es
    // solo el efectivo; el resto se confronta contra el datáfono y el
    // extracto, y sin este detalle la diferencia aparece pero no se sabe de
    // dónde sale.
    $porMetodo = collect($resumen['payment_breakdown'] ?? [])
        ->filter(fn ($monto) => abs((float) $monto) > 0.009)
        ->sortKeys();
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Arqueo de caja #{{ $sesion->id }}</title>
    <style>
        @page { size: {{ $widthMm }} auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0; background: #f1f1f1; color: #000;
            font-family: {{ $isThermal ? "'Courier New', monospace" : 'system-ui, sans-serif' }};
        }
        /* Térmica: TODO en negro puro. Cualquier gris se imprime como puntos
           salteados y sale clarito — la causa número uno de tiquetes que no se
           alcanzan a leer. */
        .ticket {
            width: {{ $widthMm }}; margin: 0 auto; background: #fff;
            padding: 6mm 4mm; font-size: {{ $isThermal ? '12px' : '13px' }}; line-height: 1.45;
        }
        .row { display: flex; justify-content: space-between; gap: 6px; }
        .row span:last-child { text-align: right; white-space: nowrap; }
        .bold { font-weight: 700; }
        .center { text-align: center; }
        .sep { border-top: 1px dashed #000; margin: 6px 0; }
        .solid { border-top: 2px solid #000; margin: 6px 0; }
        .small { font-size: {{ $isThermal ? '11px' : '12px' }}; }
        .big { font-size: {{ $isThermal ? '15px' : '17px' }}; font-weight: 800; }
        h1 { font-size: {{ $isThermal ? '14px' : '16px' }}; margin: 0 0 2px; }
        @media print { html, body { background: #fff; } .noprint { display: none; } }
    </style>
</head>
<body onload="window.print()">
<div class="ticket">

    <div class="center">
        <h1>{{ $empresa?->name }}</h1>
        @if ($empresa?->nit)
            <div class="small">NIT {{ $empresa->nit }}</div>
        @endif
        <div class="small">{{ $sede?->fullName() }}</div>
        <div class="sep"></div>
        <div class="bold">ARQUEO DE CAJA</div>
        <div class="small">Turno #{{ $sesion->id }}</div>
    </div>

    <div class="sep"></div>

    <div class="row small"><span>Cajero</span><span>{{ $sesion->cashier?->name ?? '—' }}</span></div>
    <div class="row small">
        <span>Apertura</span>
        <span>{{ $sesion->opened_at?->format(ClockFormat::datetime()) ?? '—' }}</span>
    </div>
    <div class="row small">
        <span>Cierre</span>
        <span>{{ $cerrada ? ($sesion->closed_at?->format(ClockFormat::datetime()) ?? '—') : 'EN CURSO' }}</span>
    </div>

    <div class="sep"></div>

    {{-- VENTAS POR MÉTODO DE PAGO
         Lo que el cierre necesita para cuadrarse contra algo: el efectivo
         contra el cajón, lo demás contra el datáfono y el extracto. --}}
    <div class="bold">COBRADO POR MÉTODO</div>
    @forelse ($porMetodo as $metodo => $monto)
        <div class="row">
            <span>{{ PaymentMethodOptions::nombre($metodo) }}</span>
            <span>{{ $plata($monto) }}</span>
        </div>
    @empty
        <div class="small">Sin movimientos en este turno.</div>
    @endforelse

    <div class="solid"></div>
    <div class="row bold">
        <span>TOTAL COBRADO</span>
        <span>{{ $plata($porMetodo->sum()) }}</span>
    </div>

    <div class="sep"></div>

    {{-- DETALLE DEL TURNO --}}
    <div class="bold">MOVIMIENTO DEL TURNO</div>
    <div class="row"><span>Ventas ({{ $resumen['sales']['count'] }})</span><span>{{ $plata($resumen['sales']['total']) }}</span></div>

    @if ($resumen['purchases']['total'] > 0.009)
        <div class="row"><span>Pagos a proveedores</span><span>− {{ $plata($resumen['purchases']['total']) }}</span></div>
    @endif
    @if ($resumen['expenses']['total'] > 0.009)
        <div class="row"><span>Gastos</span><span>− {{ $plata($resumen['expenses']['total']) }}</span></div>
    @endif
    @if (! empty($resumen['refunds']) && $resumen['refunds']['total'] > 0.009)
        <div class="row"><span>Devoluciones</span><span>− {{ $plata($resumen['refunds']['total']) }}</span></div>
    @endif

    {{-- Entradas y salidas directas, desglosadas por motivo: un faltante que
         en realidad fue el pago del domiciliario deja de parecer faltante. --}}
    @if (! empty($resumen['movements']) && $resumen['movements']['count'] > 0)
        <div class="sep"></div>
        <div class="bold">MOVIMIENTOS DE CAJA</div>
        @foreach ($resumen['movements']['por_motivo'] as $motivo => $monto)
            <div class="row small">
                <span>{{ $motivo }}</span>
                <span>{{ $monto >= 0 ? '' : '− ' }}{{ $plata(abs($monto)) }}</span>
            </div>
        @endforeach
        <div class="row">
            <span>Neto movimientos</span>
            <span>{{ $resumen['movements']['neto'] >= 0 ? '' : '− ' }}{{ $plata(abs($resumen['movements']['neto'])) }}</span>
        </div>
    @endif

    <div class="solid"></div>

    {{-- EL CUADRE, que es a lo que viene el papel --}}
    <div class="row"><span>Base de apertura</span><span>{{ $plata($sesion->opening_amount) }}</span></div>
    <div class="row bold"><span>EFECTIVO ESPERADO</span><span>{{ $plata($esperado) }}</span></div>

    @if ($contado !== null)
        <div class="row bold"><span>EFECTIVO CONTADO</span><span>{{ $plata($contado) }}</span></div>
        <div class="solid"></div>
        <div class="row big">
            <span>{{ $diferencia >= 0 ? 'SOBRANTE' : 'FALTANTE' }}</span>
            <span>{{ $plata(abs($diferencia)) }}</span>
        </div>
    @else
        <div class="small center" style="margin-top:4px;">
            El turno sigue abierto: el conteo y la diferencia se calculan al cerrarlo.
        </div>
    @endif

    @if ($sesion->closing_notes)
        <div class="sep"></div>
        <div class="small"><span class="bold">Observaciones:</span> {{ $sesion->closing_notes }}</div>
    @endif

    {{-- La firma: es el motivo por el que esto se imprime en papel. --}}
    @if ($cerrada)
        <div style="margin-top:14mm;">
            <div class="sep"></div>
            <div class="center small">Firma del cajero</div>
        </div>
        <div style="margin-top:10mm;">
            <div class="sep"></div>
            <div class="center small">Recibe</div>
        </div>
    @endif

    <div class="sep"></div>
    <div class="center small">Impreso {{ now()->format(ClockFormat::datetime()) }}</div>

</div>

<div class="noprint center" style="padding:10px;">
    <button onclick="window.print()">Imprimir</button>
    <button onclick="window.close()">Cerrar</button>
</div>
</body>
</html>
