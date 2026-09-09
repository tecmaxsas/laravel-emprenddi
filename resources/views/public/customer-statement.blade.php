{{--
    Estado de cuenta que el cliente abre desde WhatsApp.

    Se diseña para celular primero: casi nadie va a abrir esto en un computador.
    La tabla de siete columnas del PDF no cabe en una pantalla de 360 px, así que
    en móvil cada movimiento se muestra como una tarjeta y la tabla aparece solo
    cuando hay ancho para leerla.

    Página autónoma, con su CSS en línea: no puede depender del bundle de Vite.
--}}
@php
    $moneda = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    $etiquetas = [
        'factura' => 'Factura',
        'abono' => 'Abono',
        'anticipo' => 'Anticipo',
        'apertura' => 'Saldo anterior',
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de cuenta — {{ $customer->name }}</title>

    {{-- Información financiera de un tercero: nunca en un buscador. --}}
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --tinta: #0f172a;
            --suave: #64748b;
            --linea: #e2e8f0;
            --fondo: #f1f5f9;
            --tarjeta: #ffffff;
            --deuda: #b91c1c;
            --favor: #15803d;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--fondo);
            color: var(--tinta);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 15px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .hoja { max-width: 860px; margin: 0 auto; padding: 0 14px 48px; }

        .cabecera {
            background: var(--tinta);
            color: #fff;
            padding: 26px 0 22px;
            margin-bottom: 18px;
        }

        .cabecera .hoja { padding-bottom: 0; }
        .cabecera h1 { margin: 0; font-size: 1.15rem; font-weight: 600; }
        .cabecera .datos { margin-top: 4px; font-size: .82rem; opacity: .75; }

        .tarjeta {
            background: var(--tarjeta);
            border: 1px solid var(--linea);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 14px;
        }

        .cliente strong { display: block; font-size: 1.05rem; }
        .cliente .meta { color: var(--suave); font-size: .85rem; margin-top: 2px; }

        /* -------------------------------------------------------- el saldo */
        .saldo { text-align: center; padding: 26px 18px; }
        .saldo .rotulo { color: var(--suave); font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; }

        .saldo .valor {
            font-size: 2.4rem;
            font-weight: 700;
            margin: 6px 0 2px;
            line-height: 1.1;
        }

        .saldo .valor.debe { color: var(--deuda); }
        .saldo .valor.favor { color: var(--favor); }
        .saldo .nota { color: var(--suave); font-size: .85rem; }

        .resumen {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--linea);
            text-align: start;
        }

        .resumen div span { display: block; color: var(--suave); font-size: .78rem; }
        .resumen div strong { font-size: 1rem; }

        /* --------------------------------------------------- movimientos */
        h2 { font-size: .95rem; margin: 22px 0 10px; }

        .movimiento {
            background: var(--tarjeta);
            border: 1px solid var(--linea);
            border-radius: 12px;
            padding: 13px 15px;
            margin-bottom: 9px;
        }

        .movimiento .fila { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; }
        .movimiento .detalle { color: var(--suave); font-size: .85rem; margin-top: 3px; }
        .movimiento .corrido { color: var(--suave); font-size: .8rem; margin-top: 6px; }

        .tipo {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 600;
        }

        .t-factura { background: #fee2e2; color: #991b1b; }
        .t-abono { background: #dcfce7; color: #166534; }
        .t-anticipo { background: #dbeafe; color: #1e40af; }
        .t-apertura { background: #f1f5f9; color: #475569; }

        .importe { font-weight: 600; white-space: nowrap; }
        .importe.debe { color: var(--deuda); }
        .importe.haber { color: var(--favor); }

        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        table th { text-align: start; padding: 9px 8px; color: var(--suave); font-size: .74rem;
                   text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid var(--linea); }
        table td { padding: 10px 8px; border-bottom: 1px solid var(--linea); }
        table .num { text-align: end; white-space: nowrap; }

        /* La tabla solo cuando hay ancho para leerla. */
        .solo-ancho { display: none; }
        @media (min-width: 720px) {
            .solo-ancho { display: block; }
            .solo-angosto { display: none; }
        }

        .vacio { text-align: center; color: var(--suave); padding: 26px; }

        .pie { color: var(--suave); font-size: .78rem; text-align: center; margin-top: 22px; }
        .pie p { margin: 4px 0; }
    </style>
</head>
<body>

<header class="cabecera">
    <div class="hoja">
        <h1>{{ $company?->name }}</h1>
        <div class="datos">
            @if ($company?->nit) NIT {{ $company->nit }} · @endif
            Estado de cuenta al {{ now()->format('d/m/Y') }}
        </div>
    </div>
</header>

<main class="hoja">

    <section class="tarjeta cliente">
        <strong>{{ $customer->name }}</strong>
        <div class="meta">
            {{ trim(mb_strtoupper((string) $customer->document_type).' '.$customer->document_number) }}
            @if ($from || $to)
                <br>Período: {{ $from ? \Carbon\Carbon::parse($from)->format('d/m/Y') : 'el inicio' }}
                a {{ $to ? \Carbon\Carbon::parse($to)->format('d/m/Y') : 'hoy' }}
            @endif
        </div>
    </section>

    <section class="tarjeta saldo">
        <div class="rotulo">{{ $due >= 0 ? 'Saldo pendiente' : 'Saldo a favor' }}</div>

        <div class="valor {{ $due > 0.01 ? 'debe' : 'favor' }}">
            {{ $moneda(abs($due)) }}
        </div>

        <div class="nota">
            @if (abs($due) <= 0.01)
                Está al día. Gracias.
            @elseif ($due < 0)
                Este valor queda a su favor para su próxima compra.
            @else
                Este es el valor pendiente a la fecha.
            @endif
        </div>

        <div class="resumen">
            @if (abs($opening_balance) > 0.01)
                <div><span>Saldo anterior</span><strong>{{ $moneda($opening_balance) }}</strong></div>
            @endif
            <div><span>Total facturado</span><strong>{{ $moneda($invoiced) }}</strong></div>
            <div><span>Total abonado</span><strong>{{ $moneda($paid) }}</strong></div>
            @if ($advance_balance > 0.01)
                <div><span>Anticipos sin aplicar</span><strong>{{ $moneda($advance_balance) }}</strong></div>
            @endif
        </div>
    </section>

    <h2>Movimientos</h2>

    @if ($movements->isEmpty())
        <div class="tarjeta vacio">No hay movimientos en este período.</div>
    @else
        {{-- Celular: una tarjeta por movimiento. --}}
        <div class="solo-angosto">
            @foreach ($movements as $m)
                <article class="movimiento">
                    <div class="fila">
                        <span class="tipo t-{{ $m['type'] }}">{{ $etiquetas[$m['type']] ?? $m['type'] }}</span>
                        <span class="importe {{ $m['debit'] > 0 ? 'debe' : 'haber' }}">
                            {{ $m['debit'] > 0 ? '+'.$moneda($m['debit']) : '−'.$moneda($m['credit']) }}
                        </span>
                    </div>

                    <div class="detalle">
                        {{ $m['date'] }}
                        @if ($m['reference']) · {{ $m['reference'] }} @endif
                    </div>

                    @if ($m['description'])
                        <div class="detalle">{{ $m['description'] }}</div>
                    @endif

                    <div class="corrido">Saldo después: <strong>{{ $moneda($m['balance']) }}</strong></div>
                </article>
            @endforeach
        </div>

        {{-- Pantalla ancha: la tabla completa. --}}
        <div class="tarjeta solo-ancho">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Referencia</th>
                        <th>Detalle</th>
                        <th class="num">Cargo</th>
                        <th class="num">Abono</th>
                        <th class="num">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr>
                            <td>{{ $m['date'] }}</td>
                            <td><span class="tipo t-{{ $m['type'] }}">{{ $etiquetas[$m['type']] ?? $m['type'] }}</span></td>
                            <td>{{ $m['reference'] }}</td>
                            <td>{{ $m['description'] }}</td>
                            <td class="num">{{ $m['debit'] > 0 ? $moneda($m['debit']) : '' }}</td>
                            <td class="num">{{ $m['credit'] > 0 ? $moneda($m['credit']) : '' }}</td>
                            <td class="num"><strong>{{ $moneda($m['balance']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <footer class="pie">
        @if ($company?->phone || $company?->email)
            <p>
                ¿Dudas? Escríbenos
                @if ($company?->phone) al {{ $company->phone }} @endif
                @if ($company?->email) · {{ $company->email }} @endif
            </p>
        @endif
        <p>Los valores de las facturas son el neto a pagar, ya descontadas las retenciones.</p>
        <p>Este enlace es personal y deja de funcionar el {{ $share->expires_at->format('d/m/Y') }}.</p>
    </footer>

</main>

</body>
</html>
