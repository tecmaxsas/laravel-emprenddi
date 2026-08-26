@php
    /** @var \App\Models\Restaurant\KitchenTicket $ticket */
    $order = $ticket->order;
    $tableLabel = $order->table?->code ?? ($order->is_delivery ? 'DELIVERY' : 'TAKEAWAY');
    $meta = $order->delivery_metadata ?? [];

    // Mismo agrupamiento por curso que el ESC/POS, para que la comanda impresa
    // por navegador se lea igual que la de la impresora termica.
    $byCourse = [];
    foreach ($ticket->items_snapshot ?? [] as $it) {
        $byCourse[(int) ($it['course'] ?? 1)][] = $it;
    }
    ksort($byCourse);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comanda {{ $order->fullNumber() }}</title>
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

        .printer-name { font-size: 15px; font-weight: 700; text-transform: uppercase; }
        .flag { font-size: 20px; font-weight: 700; margin: 4px 0; }
        .table-label { font-size: 26px; font-weight: 700; letter-spacing: .02em; margin: 2px 0 4px; }
        .meta { font-size: 11px; }

        .course { font-weight: 700; text-align: center; margin: 8px 0 4px; text-transform: uppercase; }

        .item { margin-bottom: 8px; }
        .item-main { font-size: 17px; font-weight: 700; text-transform: uppercase; line-height: 1.2; }
        .item-sub { padding-left: 14px; font-size: 12px; }
        .item-note { padding-left: 14px; font-weight: 700; }

        .footer { font-size: 11px; margin-top: 4px; }

        /* Aviso que solo se ve en pantalla: al imprimir estorba */
        .screen-only { margin: 10px 0; text-align: center; }
        @media print { .screen-only { display: none !important; } }

        button {
            font: inherit;
            padding: 6px 14px;
            margin: 0 3px;
            cursor: pointer;
        }
    </style>
</head>
<body>

<div class="screen-only">
    <button onclick="window.print()">Imprimir</button>
    <button onclick="window.close()">Cerrar</button>
</div>

<div class="center">
    <div class="printer-name">{{ $ticket->printer?->name ?? 'COCINA' }}</div>

    @if ($order->is_takeaway)
        <div class="flag">** PARA LLEVAR **</div>
    @elseif ($order->is_delivery)
        <div class="flag">** DELIVERY **</div>
        @if (! empty($meta['address']))
            <div class="meta">Dir: {{ $meta['address'] }}</div>
        @endif
        @if (! empty($meta['address_notes']))
            <div class="meta">Ref: {{ $meta['address_notes'] }}</div>
        @endif
        @if (! empty($meta['customer_phone']))
            <div class="meta">Tel: {{ $meta['customer_phone'] }}</div>
        @endif
    @endif

    <div class="table-label">MESA {{ $tableLabel }}</div>

    <div class="meta">{{ $order->fullNumber() }}</div>
    <div class="meta">{{ $ticket->printed_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s') }}</div>
    <div class="meta">Mesero: {{ $order->server?->name ?? '—' }}</div>
    <div class="meta">{{ $order->guests }} comensal{{ $order->guests > 1 ? 'es' : '' }}</div>

    @if (! empty($meta['customer_name']))
        <div class="meta bold">Cliente: {{ $meta['customer_name'] }}</div>
    @endif
</div>

<div class="sep-strong"></div>

@foreach ($byCourse as $courseNum => $items)
    <div class="course">
        --- {{ \App\Models\Restaurant\OrderItem::COURSES[$courseNum] ?? ('CURSO '.$courseNum) }} ---
    </div>

    @foreach ($items as $item)
        <div class="item">
            <div class="item-main">
                {{ (int) ($item['quantity'] ?? 1) }}x&nbsp; {{ $item['description'] ?? '?' }}
            </div>

            @foreach (($item['modifiers'] ?? []) as $mod)
                @php $modName = is_array($mod) ? ($mod['name'] ?? '') : (string) $mod; @endphp
                @if ($modName)
                    <div class="item-sub">+ {{ $modName }}</div>
                @endif
            @endforeach

            @if (! empty($item['note']))
                <div class="item-note">NOTA: {{ $item['note'] }}</div>
            @endif

            @if (! empty($item['split_tab']))
                <div class="item-sub">({{ $item['split_tab'] }})</div>
            @endif
        </div>
    @endforeach
@endforeach

<div class="sep"></div>

<div class="center footer">
    <div>Comanda #{{ $ticket->batch_number }}</div>
    <div>Pedido a {{ $ticket->printed_at?->format('H:i') ?? now()->format('H:i') }}</div>
</div>

<script>
    // Auto-imprime al cargar y cierra la ventana al terminar, para que el
    // mesero no tenga que hacer nada. Si el navegador bloquea el dialogo, los
    // botones de arriba siguen disponibles.
    window.addEventListener('load', function () {
        window.print();
    });
    window.addEventListener('afterprint', function () {
        window.close();
    });
</script>

</body>
</html>
