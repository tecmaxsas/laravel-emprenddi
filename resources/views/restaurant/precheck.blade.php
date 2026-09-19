@php
    /** @var \App\Models\Restaurant\Order $order */
    /** @var \App\Models\Company $company */
    /** @var array $config */

    $mesa = $order->table?->code
        ?? ($order->is_delivery ? 'DOMICILIO' : ($order->is_takeaway ? 'PARA LLEVAR' : 'SIN MESA'));

    $consumo = (float) $order->total;
    $propina = \App\Support\PrecuentaSettings::propinaSugerida($consumo);

    $plata = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Precuenta {{ $order->fullNumber() }}</title>
    <style>
        /* 80mm de ancho util, como la termica */
        @page { size: 80mm auto; margin: 3mm; }

        * { box-sizing: border-box; }

        body {
            font-family: "Courier New", Courier, monospace;
            width: 74mm;
            margin: 0 auto;
            padding: 4px 0 12px;
            color: #000;
            background: #fff;
            font-size: 12px;
            line-height: 1.35;
        }

        .center { text-align: center; }
        .bold { font-weight: 700; }
        .sep { border-top: 1px dashed #000; margin: 6px 0; }
        .sep-strong { border-top: 2px solid #000; margin: 6px 0; }

        .company-name { font-size: 15px; font-weight: 700; text-transform: uppercase; }
        .meta { font-size: 11px; }
        .table-label { font-size: 22px; font-weight: 700; margin: 4px 0 2px; }

        /*
         * LA LEYENDA.
         *
         * Va arriba, grande y con marco, y no es configurable. Una precuenta se
         * imprime en la misma impresora que la factura, se parece y lleva los
         * mismos totales: si no dice con todas las letras que no es una
         * factura, el cliente se va creyendo que ya la tiene. En Colombia eso
         * es un problema con la DIAN, no un detalle de diseno.
         */
        .aviso {
            border: 2px solid #000;
            padding: 5px 4px;
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            line-height: 1.25;
            margin: 6px 0;
            text-transform: uppercase;
        }

        .row { display: flex; justify-content: space-between; gap: 6px; }
        .row-total { font-size: 15px; font-weight: 700; }

        .item { margin-bottom: 5px; }
        .item-main { display: flex; justify-content: space-between; gap: 6px; font-weight: 700; }
        .item-qty { min-width: 26px; }
        .item-name { flex: 1; }
        .item-sub { padding-left: 26px; font-size: 11px; }

        .footer { font-size: 11px; margin-top: 6px; white-space: pre-line; text-align: center; }

        /* Solo en pantalla: al imprimir estorba */
        .screen-only { margin: 10px 0; text-align: center; }
        @media print { .screen-only { display: none !important; } }

        button {
            padding: 10px 18px; font-size: 14px; font-weight: 700;
            border: 0; border-radius: 8px; background: #4f46e5; color: #fff; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="center">
        <div class="company-name">{{ $company->name }}</div>
        @if ($company->nit)
            <div class="meta">NIT {{ $company->nit }}{{ $company->dv ? '-'.$company->dv : '' }}</div>
        @endif
        @if ($company->address)
            <div class="meta">{{ $company->address }}</div>
        @endif
        @if ($company->phone)
            <div class="meta">Tel. {{ $company->phone }}</div>
        @endif
    </div>

    <div class="aviso">
        Precuenta<br>
        Este documento NO es una factura de venta
    </div>

    <div class="center">
        <div class="table-label">{{ $mesa }}</div>
        <div class="meta">Orden {{ $order->fullNumber() }}</div>
        <div class="meta">{{ now()->format(\App\Support\ClockFormat::datetime()) }}</div>
        @if ($order->server)
            <div class="meta">Atendido por: {{ $order->server->name ?: $order->server->email }}</div>
        @endif
        @if ($order->guests)
            <div class="meta">{{ $order->guests }} persona(s)</div>
        @endif
    </div>

    <div class="sep"></div>

    @forelse ($order->items as $item)
        <div class="item">
            <div class="item-main">
                <span class="item-qty">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}x</span>
                <span class="item-name">{{ $item->description }}</span>
                <span>{{ $plata($item->total) }}</span>
            </div>

            {{-- Los modificadores van listados porque son parte de lo que se
                 cobra: un «extra queso» que el cliente no pidio se discute
                 aqui, no cuando ya hay factura. --}}
            @foreach ((array) ($item->modifiers ?? []) as $mod)
                <div class="item-sub">
                    + {{ is_array($mod) ? ($mod['name'] ?? $mod['description'] ?? '') : $mod }}
                    @if (is_array($mod) && ! empty($mod['price']))
                        {{ $plata($mod['price']) }}
                    @endif
                </div>
            @endforeach

            @if ($item->item_note)
                <div class="item-sub">{{ $item->item_note }}</div>
            @endif
        </div>
    @empty
        <div class="center meta">Esta orden todavía no tiene consumos.</div>
    @endforelse

    <div class="sep"></div>

    <div class="row"><span>Subtotal</span><span>{{ $plata($order->subtotal) }}</span></div>

    @if ((float) $order->discount_total > 0)
        <div class="row"><span>Descuento</span><span>− {{ $plata($order->discount_total) }}</span></div>
    @endif

    @if ((float) $order->tax_total > 0)
        <div class="row"><span>Impuestos</span><span>{{ $plata($order->tax_total) }}</span></div>
    @endif

    @if ((float) $order->delivery_fee > 0)
        <div class="row"><span>Domicilio</span><span>{{ $plata($order->delivery_fee) }}</span></div>
    @endif

    <div class="sep-strong"></div>
    <div class="row row-total"><span>CONSUMO</span><span>{{ $plata($consumo) }}</span></div>

    {{-- LA PROPINA VA APARTE DEL CONSUMO, SIEMPRE.
         Sumarla al total la convierte en algo que el cliente cree que debe.
         Es voluntaria: se muestra como sugerencia y el total con propina se
         imprime como una segunda cifra, no como LA cifra. --}}
    @if ($propina > 0)
        <div class="sep"></div>
        <div class="row">
            <span>Propina sugerida ({{ rtrim(rtrim(number_format($config['tip_percent'], 1, ',', '.'), '0'), ',') }}%)</span>
            <span>{{ $plata($propina) }}</span>
        </div>
        <div class="row bold"><span>Total con propina</span><span>{{ $plata($consumo + $propina) }}</span></div>
    @endif

    @if ($config['footer'] !== '')
        <div class="sep"></div>
        <div class="footer">{{ $config['footer'] }}</div>
    @endif

    <div class="screen-only">
        <button type="button" onclick="window.print()">🖨️ Imprimir precuenta</button>
    </div>

    <script>
        // Se imprime sola, como la comanda de cocina: el mesero la manda desde
        // el POS y va camino a la mesa, no a leer la pantalla.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
