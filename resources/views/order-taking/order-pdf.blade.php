<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedido {{ $order->fullNumber() }}</title>
    <style>
        {{-- Formato calcado del reporte diario del cliente: cabecera en tres
             bloques, tabla densa con rejilla completa y franjas grises de
             totales. Sin colores de marca ni cajas redondeadas: el documento
             se imprime y se archiva. --}}
        @page { size: A4 landscape; margin: 10mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #000; margin: 0; }

        .head { display: table; width: 100%; margin-bottom: 6px; }
        .head > div { display: table-cell; vertical-align: top; }
        .head .left  { width: 33%; text-align: left; }
        .head .mid   { width: 34%; text-align: center; }
        .head .right { width: 33%; text-align: right; }
        .co-name { font-size: 10px; font-weight: bold; }
        .doc-title { font-size: 11px; font-weight: bold; letter-spacing: .5px; }

        .meta { margin: 4px 0 6px; font-size: 8.5px; }
        .meta span { margin-right: 10px; }
        .meta b { font-weight: bold; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th,
        table.grid td { border: 1px solid #000; padding: 2.5px 4px; vertical-align: top; }
        table.grid th { font-weight: bold; text-align: left; }
        table.grid td.num,
        table.grid th.num { text-align: right; white-space: nowrap; }
        table.grid td.mid,
        table.grid th.mid { text-align: center; white-space: nowrap; }

        /* Franjas de totales, como los cortes por documento y cliente del
           reporte de referencia. */
        tr.band td { background: #d9d9d9; font-weight: bold; }
        tr.band-strong td { background: #bfbfbf; font-weight: bold; }
        tr.ret td { background: #f2f2f2; }

        .notes { margin-top: 8px; padding: 4px 8px; border: 1px solid #000; font-size: 8.5px; }
        .sig-box { margin-top: 26px; display: table; width: 100%; }
        .sig-cell { display: table-cell; width: 50%; padding: 0 30px; }
        .sig-line { border-top: 1px solid #000; padding-top: 2px; font-size: 8px; text-align: center; }
        .foot { margin-top: 10px; font-size: 7.5px; color: #444; }
    </style>
</head>
<body>
    @php
        $money = fn ($n) => '$'.number_format((float) $n, 2, ',', '.');
        $qty = fn ($n) => number_format((float) $n, 2, ',', '.');

        // El vendedor se repite en cada fila igual que en el reporte de
        // referencia, que trae una columna por linea y no un dato de cabecera.
        $vendedor = $order->seller?->name ?? '—';
        $fechaPedido = $order->order_date?->format('d/m/Y');

        $totalCantidad = $order->items->sum('quantity_ordered');
    @endphp

    <div class="head">
        <div class="left">
            <div class="co-name">{{ strtoupper($company->name ?? '') }}</div>
            <div>@if ($company?->nit){{ $company->nit }}{{ $company->dv ? '-'.$company->dv : '' }}@endif</div>
            <div>
                @if ($company?->address){{ $company->address }}@endif
                @if ($company?->city) · {{ $company->city }}@endif
                @if ($company?->phone) · Tel {{ $company->phone }}@endif
            </div>
        </div>
        <div class="mid">
            <div class="doc-title">PEDIDO</div>
            <div>{{ $order->fullNumber() }}</div>
        </div>
        <div class="right">
            <div>{{ $fechaPedido }}</div>
            <div>{{ now()->format('g:i A') }}</div>
        </div>
    </div>

    <div class="meta">
        <span><b>Cliente:</b> {{ $order->customer?->name ?? '—' }}
            @if ($order->customer?->document_number) · NIT {{ $order->customer->document_number }}@endif</span>
        <span><b>Dirección:</b> {{ $order->customer?->address ?? '—' }}@if ($order->customer?->city), {{ $order->customer->city }}@endif</span>
        <br>
        <span><b>Forma de pago:</b> {{ $order->customer?->payment_terms ?? '—' }}</span>
        <span><b>Horario recibo:</b> {{ $order->customer?->delivery_horario ?? '—' }}</span>
        @if ($order->priceList)<span><b>Lista:</b> {{ $order->priceList->name }}</span>@endif
        @if ($order->delivery_date_expected)<span><b>Entrega esperada:</b> {{ $order->delivery_date_expected->format('d/m/Y') }}</span>@endif
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th style="width: 11%;">Nombre vend.</th>
                <th style="width: 7%;">Referencia</th>
                <th>Desc. ítem</th>
                <th class="mid" style="width: 8%;">Fecha</th>
                <th class="mid" style="width: 5%;">U.M.</th>
                <th class="num" style="width: 5%;">Cant.</th>
                <th class="num" style="width: 10%;">Precio unit.</th>
                <th class="num" style="width: 12%;">Valor subtotal</th>
                <th class="num" style="width: 10%;">Vlr. imp. IVA</th>
                <th class="num" style="width: 12%;">Valor neto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $vendedor }}</td>
                    <td>{{ $item->product?->code }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="mid">{{ $fechaPedido }}</td>
                    <td class="mid">{{ $item->product?->unit_of_measure ?: '—' }}</td>
                    <td class="num">{{ $qty($item->quantity_ordered) }}</td>
                    <td class="num">{{ $money($item->unit_price_before_tax ?: $item->unit_price_at_public) }}</td>
                    <td class="num">{{ $money($item->subtotal ?: $item->total) }}</td>
                    <td class="num">{{ $money($item->tax_amount) }}</td>
                    <td class="num">{{ $money($item->total) }}</td>
                </tr>
            @endforeach

            {{-- Corte por documento: el equivalente a la franja "FE-000xxxxx"
                 del reporte de referencia. --}}
            <tr class="band">
                <td colspan="5">{{ $order->fullNumber() }} · {{ $order->customer?->name ?? '—' }}</td>
                <td class="num">{{ $qty($totalCantidad) }}</td>
                <td></td>
                <td class="num">{{ $money($order->subtotal) }}</td>
                <td class="num">{{ $money($order->tax_total) }}</td>
                <td class="num">{{ $money($order->total) }}</td>
            </tr>

            {{-- El cliente necesita ver que se le retuvo y con que tarifa. --}}
            @if ((float) $order->retention_total > 0)
                @foreach ($order->retentions as $ret)
                    <tr class="ret">
                        <td colspan="9">{{ $ret->tax_name }} — {{ $ret->tax_code }} ({{ $ret->rateLabel() }}%) sobre base {{ $money($ret->base_amount) }}</td>
                        <td class="num">− {{ $money($ret->amount) }}</td>
                    </tr>
                @endforeach
            @endif

            <tr class="band-strong">
                <td colspan="5">{{ (float) $order->retention_total > 0 ? 'Neto a pagar' : 'Gran total' }}</td>
                <td class="num">{{ $qty($totalCantidad) }}</td>
                <td></td>
                <td class="num">{{ $money($order->subtotal) }}</td>
                <td class="num">{{ $money($order->tax_total) }}</td>
                <td class="num">{{ $money($order->net_payable ?: $order->total) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($order->notes)
        <div class="notes"><b>Notas:</b> {{ $order->notes }}</div>
    @endif

    <div class="sig-box">
        <div class="sig-cell"><div class="sig-line">Elaborado por</div></div>
        <div class="sig-cell"><div class="sig-line">Recibido por</div></div>
    </div>

    <div class="foot">
        Documento generado por Emprenddi · {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
