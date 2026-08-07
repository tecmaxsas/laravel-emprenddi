<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #0f172a; max-width: 620px; margin: 0 auto; padding: 20px; }
        .header { background: #0f172a; color: #fff; padding: 14px 18px; border-radius: 8px 8px 0 0; }
        .box { background: #f8fafc; padding: 18px; border-radius: 0 0 8px 8px; border: 1px solid #e2e8f0; border-top: 0; }
        .summary { display: table; width: 100%; margin: 14px 0; }
        .summary .cell { display: table-cell; padding: 4px 8px; font-size: 13px; }
        .foot { margin-top: 20px; font-size: 11px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2 style="margin: 0;">{{ $company?->name }}</h2>
        <div style="font-size: 12px; opacity: .85;">Pedido {{ $order->fullNumber() }}</div>
    </div>
    <div class="box">
        @if (! empty($body))
            <p style="white-space: pre-line;">{{ $body }}</p>
        @else
            <p>Buen día,</p>
            <p>Adjunto encontrarás el pedido <strong>{{ $order->fullNumber() }}</strong> de fecha {{ $order->order_date?->format('d/m/Y') }}.</p>
        @endif

        <div class="summary">
            <div class="cell"><strong>Total:</strong> $ {{ number_format($order->total, 0, ',', '.') }}</div>
            <div class="cell"><strong>Líneas:</strong> {{ $order->items->count() }}</div>
            @if ($order->delivery_date_expected)
                <div class="cell"><strong>Entrega esperada:</strong> {{ $order->delivery_date_expected->format('d/m/Y') }}</div>
            @endif
        </div>

        <p style="font-size: 13px;">Ante cualquier duda respondemos a este correo.</p>
        <p style="font-size: 13px;">Saludos,<br>{{ $company?->name }}</p>
    </div>
    <div class="foot">
        Enviado desde Emprenddi · {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
