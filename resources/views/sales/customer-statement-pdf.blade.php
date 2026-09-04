<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estado de cuenta — {{ $customer->name }}</title>
    <style>
        @page { margin: 22mm 14mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #111827; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .header td { vertical-align: top; }
        .cliente { background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 10px; margin-bottom: 12px; }
        .cliente strong { font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        .mov th { background: #111827; color: #fff; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; }
        .mov td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        .mov tr:nth-child(even) td { background: #fafafa; }
        .num { text-align: right; }
        .resumen { margin-top: 14px; width: 46%; float: right; }
        .resumen td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .resumen .total td { border-top: 2px solid #111827; border-bottom: 0; font-size: 12px; font-weight: bold; }
        .aviso { clear: both; padding-top: 26px; font-size: 9px; }
        .tipo { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 8px; text-transform: uppercase; }
        .t-factura { background: #fee2e2; color: #991b1b; }
        .t-abono { background: #dcfce7; color: #166534; }
        .t-anticipo { background: #dbeafe; color: #1e40af; }
        .t-apertura { background: #f3f4f6; color: #374151; }
    </style>
</head>
<body>

<table class="header">
    <tr>
        <td>
            <h1>{{ $company->name }}</h1>
            <div class="muted">
                NIT {{ $company->document_number ?? '' }}<br>
                {{ $company->address ?? '' }}<br>
                {{ $company->phone ?? '' }}
            </div>
        </td>
        <td style="text-align:right;">
            <h1>ESTADO DE CUENTA</h1>
            <div class="muted">
                Generado el {{ now()->format('Y-m-d H:i') }}<br>
                @if ($from || $to)
                    Período: {{ $from ?? 'inicio' }} a {{ $to ?? 'hoy' }}
                @else
                    Todos los movimientos
                @endif
            </div>
        </td>
    </tr>
</table>

<div class="cliente">
    <strong>{{ $customer->name }}</strong><br>
    <span class="muted">
        {{ strtoupper($customer->document_type ?? '') }} {{ $customer->document_number }}
        @if ($customer->address) · {{ $customer->address }} @endif
        @if ($customer->phone) · Tel. {{ $customer->phone }} @endif
        @if ($customer->email) · {{ $customer->email }} @endif
    </span>
</div>

<table class="mov">
    <thead>
        <tr>
            <th style="width:64px;">Fecha</th>
            <th style="width:70px;">Tipo</th>
            <th style="width:90px;">Referencia</th>
            <th>Detalle</th>
            <th class="num" style="width:78px;">Débito</th>
            <th class="num" style="width:78px;">Crédito</th>
            <th class="num" style="width:82px;">Saldo</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($movements as $m)
            <tr>
                <td>{{ $m['date'] }}</td>
                <td><span class="tipo t-{{ $m['type'] }}">{{ $m['type'] }}</span></td>
                <td>{{ $m['reference'] }}</td>
                <td>{{ $m['description'] }}</td>
                <td class="num">{{ $m['debit'] > 0 ? '$'.number_format($m['debit'], 0, ',', '.') : '' }}</td>
                <td class="num">{{ $m['credit'] > 0 ? '$'.number_format($m['credit'], 0, ',', '.') : '' }}</td>
                <td class="num">${{ number_format($m['balance'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="padding:14px; text-align:center;">Este cliente no tiene movimientos.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="resumen">
    @if (abs($opening_balance) > 0.01)
        <tr><td>Saldo de apertura</td><td class="num">${{ number_format($opening_balance, 0, ',', '.') }}</td></tr>
    @endif
    <tr><td>Total facturado</td><td class="num">${{ number_format($invoiced, 0, ',', '.') }}</td></tr>
    <tr><td>Total abonado</td><td class="num">${{ number_format($paid, 0, ',', '.') }}</td></tr>
    @if ($advance_balance > 0.01)
        <tr><td>Saldo a favor</td><td class="num">${{ number_format($advance_balance, 0, ',', '.') }}</td></tr>
    @endif
    <tr class="total">
        <td>{{ $due >= 0 ? 'SALDO ADEUDADO' : 'SALDO A FAVOR' }}</td>
        <td class="num">${{ number_format(abs($due), 0, ',', '.') }}</td>
    </tr>
</table>

<div class="aviso muted">
    Débito aumenta la deuda del cliente; crédito la disminuye. El saldo de cada línea es el acumulado
    hasta ese movimiento. Los valores de las facturas son el neto a pagar, ya descontadas las retenciones.
</div>

</body>
</html>
