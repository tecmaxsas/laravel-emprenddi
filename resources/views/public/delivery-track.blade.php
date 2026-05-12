<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>Pedido {{ $order->fullNumber() }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(180deg, #f3f4f6 0%, #ffffff 100%);
            color: #111827;
            min-height: 100vh;
            padding: 20px 16px;
            line-height: 1.5;
        }
        .container { max-width: 560px; margin: 0 auto; }
        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            padding: 24px;
            margin-bottom: 16px;
        }
        .header { text-align: center; padding-bottom: 18px; border-bottom: 1px solid #e5e7eb; }
        .company-name { font-size: 14px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
        .order-number { font-size: 13px; color: #9ca3af; font-family: monospace; }
        .status-pill {
            display: inline-block;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            margin: 14px 0;
        }
        h1 { font-size: 22px; font-weight: 800; color: #111827; margin-bottom: 8px; }
        h2 { font-size: 14px; color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
        .timeline { padding: 18px 0; }
        .step {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 14px 0;
            border-left: 3px solid #e5e7eb;
            margin-left: 16px;
            padding-left: 18px;
            position: relative;
        }
        .step.done { border-left-color: #10b981; }
        .step.active { border-left-color: #3b82f6; }
        .step .icon {
            position: absolute;
            left: -16px;
            top: 14px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
        }
        .step.done .icon { background: #10b981; color: white; }
        .step.active .icon { background: #3b82f6; color: white; animation: pulse 1.5s ease-in-out infinite; }
        .step .label { font-weight: 700; color: #111827; font-size: 14px; }
        .step .time { font-size: 12px; color: #6b7280; margin-top: 2px; }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
            50% { box-shadow: 0 0 0 8px rgba(59, 130, 246, 0); }
        }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; border-bottom: 1px solid #f3f4f6; }
        .info-row:last-child { border-bottom: 0; }
        .info-row .label { color: #6b7280; }
        .info-row .value { color: #111827; font-weight: 600; text-align: right; }
        .total { display: flex; justify-content: space-between; padding: 14px 0 0; margin-top: 12px; border-top: 2px solid #d1d5db; font-size: 18px; font-weight: 800; }
        .total .value { color: #10b981; }
        .item-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; font-size: 13px; border-bottom: 1px solid #f3f4f6; }
        .item-row:last-child { border-bottom: 0; }
        .item-qty { color: #6b7280; font-weight: 600; min-width: 28px; }
        .item-name { flex: 1; }
        .item-price { color: #111827; font-weight: 600; white-space: nowrap; }
        .footer { text-align: center; padding: 20px 0; color: #9ca3af; font-size: 12px; }
        .refresh-hint { text-align: center; padding: 8px 0; color: #9ca3af; font-size: 11px; }
    </style>
</head>
<body>
    <div class="container">
        @php
            $status = $meta['delivery_status'] ?? 'preparing';
            $statusLabels = [
                'preparing' => 'En preparación',
                'ready' => 'Listo para despachar',
                'on_the_way' => 'En camino',
                'delivered' => 'Entregado',
            ];
            $statusColors = [
                'preparing' => ['bg' => '#fef3c7', 'fg' => '#92400e'],
                'ready' => ['bg' => '#dbeafe', 'fg' => '#1e40af'],
                'on_the_way' => ['bg' => '#e0e7ff', 'fg' => '#3730a3'],
                'delivered' => ['bg' => '#dcfce7', 'fg' => '#166534'],
            ];
            $color = $statusColors[$status] ?? ['bg' => '#e5e7eb', 'fg' => '#374151'];

            $stepOrder = ['preparing', 'ready', 'on_the_way', 'delivered'];
            $currentIdx = array_search($status, $stepOrder);
        @endphp

        {{-- Header --}}
        <div class="card header">
            <div class="company-name">{{ $order->company->name ?? 'Restaurante' }}</div>
            <h1>¡Hola{{ ! empty($meta['customer_name']) ? ', '.$meta['customer_name'] : '' }}!</h1>
            <div style="color:#374151; margin-bottom:8px;">Tu pedido está en camino</div>
            <div class="status-pill" style="background:{{ $color['bg'] }}; color:{{ $color['fg'] }};">
                {{ $statusLabels[$status] ?? $status }}
            </div>
            <div class="order-number">{{ $order->fullNumber() }}</div>
        </div>

        {{-- Timeline --}}
        <div class="card">
            <h2>📍 Estado del pedido</h2>
            <div class="timeline">
                @foreach ([
                    ['key' => 'preparing', 'label' => 'Recibimos tu pedido', 'icon' => '📝', 'timeKey' => 'opened_at'],
                    ['key' => 'ready', 'label' => 'Está listo para salir', 'icon' => '🍳', 'timeKey' => 'ready_at'],
                    ['key' => 'on_the_way', 'label' => 'Vas a recibirlo pronto', 'icon' => '🛵', 'timeKey' => 'dispatched_at'],
                    ['key' => 'delivered', 'label' => 'Entregado — ¡buen provecho!', 'icon' => '✓', 'timeKey' => 'delivered_at'],
                ] as $i => $step)
                    @php
                        $idx = array_search($step['key'], $stepOrder);
                        $isDone = $idx < $currentIdx || ($idx === $currentIdx && $status === 'delivered');
                        $isActive = $idx === $currentIdx && $status !== 'delivered';
                        $stepClass = $isDone ? 'done' : ($isActive ? 'active' : '');

                        // Buscar timestamp en order.opened_at o metadata
                        $time = null;
                        if ($step['timeKey'] === 'opened_at') {
                            $time = $order->opened_at;
                        } elseif (! empty($meta[$step['timeKey']])) {
                            $time = \Illuminate\Support\Carbon::parse($meta[$step['timeKey']]);
                        }
                    @endphp
                    <div class="step {{ $stepClass }}">
                        <div class="icon">{{ $isDone ? '✓' : $step['icon'] }}</div>
                        <div>
                            <div class="label">{{ $step['label'] }}</div>
                            @if ($time)
                                <div class="time">{{ $time->format('H:i') }} · hace {{ $time->diffForHumans(null, true) }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Datos de entrega --}}
        <div class="card">
            <h2>📍 Entrega</h2>
            @if (! empty($meta['address']))
                <div class="info-row">
                    <span class="label">Dirección</span>
                    <span class="value">{{ $meta['address'] }}</span>
                </div>
            @endif
            @if (! empty($meta['address_notes']))
                <div class="info-row">
                    <span class="label">Referencias</span>
                    <span class="value">{{ $meta['address_notes'] }}</span>
                </div>
            @endif
            @if (! empty($meta['driver_name']) && in_array($status, ['on_the_way', 'delivered']))
                <div class="info-row">
                    <span class="label">Repartidor</span>
                    <span class="value">🛵 {{ $meta['driver_name'] }}</span>
                </div>
            @endif
            @if (! empty($order->company->phone))
                <div class="info-row">
                    <span class="label">¿Dudas? Llámanos</span>
                    <span class="value"><a href="tel:{{ $order->company->phone }}" style="color:#3b82f6; text-decoration:none;">{{ $order->company->phone }}</a></span>
                </div>
            @endif
        </div>

        {{-- Items --}}
        <div class="card">
            <h2>🍽️ Tu pedido ({{ $items->count() }} items)</h2>
            @foreach ($items as $item)
                <div class="item-row">
                    <span class="item-qty">{{ number_format((float) $item->quantity, 0) }}×</span>
                    <span class="item-name">{{ $item->description }}</span>
                    <span class="item-price">${{ number_format((float) $item->total, 0, ',', '.') }}</span>
                </div>
            @endforeach

            <div class="info-row" style="padding-top:12px; margin-top:8px; border-top:1px dashed #d1d5db; border-bottom:0;">
                <span class="label">Subtotal</span>
                <span class="value">${{ number_format((float) $order->subtotal, 0, ',', '.') }}</span>
            </div>
            @if ((float) $order->tax_total > 0)
                <div class="info-row" style="border-bottom:0;">
                    <span class="label">Impuestos</span>
                    <span class="value">${{ number_format((float) $order->tax_total, 0, ',', '.') }}</span>
                </div>
            @endif
            @if ((float) $order->delivery_fee > 0)
                <div class="info-row" style="border-bottom:0;">
                    <span class="label">Domicilio</span>
                    <span class="value">${{ number_format((float) $order->delivery_fee, 0, ',', '.') }}</span>
                </div>
            @endif
            <div class="total">
                <span>Total</span>
                <span class="value">${{ number_format((float) $order->total, 0, ',', '.') }}</span>
            </div>
        </div>

        <div class="refresh-hint">↻ Esta página se actualiza automáticamente cada 30s</div>
        <div class="footer">
            {{ $order->company->name ?? 'Emprenddi' }} · Powered by Emprenddi
        </div>
    </div>
</body>
</html>
