<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedido {{ $order->fullNumber() }}</title>
    <style>
        @page { size: A4; margin: 15mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; margin: 0; }
        .head { display: table; width: 100%; margin-bottom: 12px; border-bottom: 2px solid #0f172a; padding-bottom: 8px; }
        .head-left, .head-right { display: table-cell; vertical-align: top; }
        .head-right { text-align: right; }
        .co-name { font-size: 14px; font-weight: bold; color: #0f172a; }
        .co-meta { font-size: 9px; color: #64748b; }
        .doc-title { font-size: 20px; font-weight: bold; color: #0f172a; }
        .doc-num { font-size: 12px; font-family: 'Courier New', monospace; color: #4f46e5; }

        .customer-box { background: #f8fafc; padding: 8px 10px; margin-bottom: 12px; border-radius: 4px; }
        .customer-box .row { display: table; width: 100%; }
        .customer-box .cell { display: table-cell; padding: 2px 6px; font-size: 10px; }
        .customer-box .lbl { color: #64748b; font-weight: bold; text-transform: uppercase; font-size: 8px; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { background: #0f172a; color: #fff; padding: 6px 5px; font-size: 9px; text-align: left; text-transform: uppercase; }
        table.items td { padding: 5px; border-bottom: 1px solid #e2e8f0; font-size: 10px; vertical-align: top; }
        table.items td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.items tr:nth-child(even) td { background: #f8fafc; }

        .totals { margin-top: 10px; margin-left: 60%; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 3px 6px; font-size: 10px; }
        .totals td.lbl { color: #64748b; font-weight: bold; }
        .totals td.val { text-align: right; font-variant-numeric: tabular-nums; }
        .totals .grand td { border-top: 2px solid #0f172a; font-size: 13px; font-weight: bold; padding-top: 5px; }

        .foot { margin-top: 30px; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 8px; }
        .sig-box { margin-top: 40px; display: table; width: 100%; }
        .sig-cell { display: table-cell; width: 50%; text-align: center; padding: 0 20px; }
        .sig-line { border-top: 1px solid #0f172a; padding-top: 3px; font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
    <div class="head">
        <div class="head-left">
            <div class="co-name">{{ strtoupper($company->name ?? '') }}</div>
            <div class="co-meta">
                @if ($company?->nit) NIT {{ $company->nit }}{{ $company->dv ? '-'.$company->dv : '' }}<br>@endif
                @if ($company?->address) {{ $company->address }}<br>@endif
                @if ($company?->city) {{ $company->city }}@endif
                @if ($company?->phone) · Tel {{ $company->phone }}@endif
                @if ($company?->email) · {{ $company->email }}@endif
            </div>
        </div>
        <div class="head-right">
            <div class="doc-title">PEDIDO</div>
            <div class="doc-num">{{ $order->fullNumber() }}</div>
            <div style="font-size: 10px; color: #64748b; margin-top: 4px;">
                Fecha: {{ $order->order_date?->format('d/m/Y') }}<br>
                @if ($order->delivery_date_expected)
                    Entrega esperada: {{ $order->delivery_date_expected->format('d/m/Y') }}
                @endif
            </div>
        </div>
    </div>

    <div class="customer-box">
        <div class="row">
            <div class="cell" style="width: 60%;">
                <span class="lbl">Cliente</span><br>
                <strong style="font-size: 12px;">{{ $order->customer?->name }}</strong><br>
                <span style="color: #64748b;">NIT {{ $order->customer?->document_number }}</span>
            </div>
            <div class="cell" style="width: 40%;">
                <span class="lbl">Contacto</span><br>
                {{ $order->customer?->contact_person ?? '—' }}<br>
                <span style="color: #64748b;">{{ $order->customer?->phone ?? $order->customer?->mobile ?? '' }}</span>
            </div>
        </div>
        <div class="row" style="margin-top: 6px;">
            <div class="cell" style="width: 60%;">
                <span class="lbl">Dirección de entrega</span><br>
                {{ $order->customer?->address ?? '—' }}
                @if ($order->customer?->city), {{ $order->customer->city }}@endif
            </div>
            <div class="cell" style="width: 40%;">
                <span class="lbl">Forma de pago</span> · {{ $order->customer?->payment_terms ?? '—' }}<br>
                <span class="lbl">Horario recibo</span> · {{ $order->customer?->delivery_horario ?? '—' }}<br>
                @if ($order->priceList)
                    <span class="lbl">Lista</span> · {{ $order->priceList->name }}<br>
                @endif
                @if ($order->seller)
                    <span class="lbl">Vendedor</span> · {{ $order->seller->name }}
                @endif
            </div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 12%;">SKU</th>
                <th>Descripción</th>
                <th style="width: 8%; text-align: right;">Cant.</th>
                <th style="width: 14%; text-align: right;">Valor unit.</th>
                <th style="width: 14%; text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td class="num">{{ $item->line_number }}</td>
                    <td style="font-family: monospace;">{{ $item->product?->code }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ (int) $item->quantity_ordered }}</td>
                    <td class="num">$ {{ number_format($item->unit_price_at_public, 0, ',', '.') }}</td>
                    <td class="num"><strong>$ {{ number_format($item->total, 0, ',', '.') }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr><td class="lbl">Subtotal</td><td class="val">$ {{ number_format($order->subtotal, 0, ',', '.') }}</td></tr>
            <tr><td class="lbl">IVA</td><td class="val">$ {{ number_format($order->tax_total, 0, ',', '.') }}</td></tr>
            <tr class="grand"><td class="lbl">TOTAL</td><td class="val">$ {{ number_format($order->total, 0, ',', '.') }}</td></tr>
        </table>
    </div>

    @if ($order->notes)
        <div style="margin-top: 12px; padding: 6px 10px; background: #fef3c7; border-left: 3px solid #f59e0b; font-size: 10px;">
            <strong>Notas:</strong> {{ $order->notes }}
        </div>
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
