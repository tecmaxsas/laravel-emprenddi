@php
    $isThermal = in_array($paperSize, ['pos_58', 'pos_80'], true);
    $widthMm = match ($paperSize) {
        'pos_58' => '58mm',
        'pos_80' => '80mm',
        'letter_half' => '108mm',
        'a5' => '148mm',
        'letter' => '215.9mm',
        'a4' => '210mm',
        default => '80mm',
    };

    $h = $settings['header'] ?? [];
    $c = $settings['customer'] ?? [];
    $l = $settings['lines'] ?? [];
    $t = $settings['totals'] ?? [];
    $f = $settings['footer'] ?? [];
    $get = static fn (array $arr, string $key, $default = true) => array_key_exists($key, $arr) ? (bool) $arr[$key] : $default;

    $company = $invoice->company;
    $location = $invoice->location;
    $customer = $invoice->customer;
    $seller = $invoice->seller;

    $totalPaid = $invoice->payments->sum('amount');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $invoice->fullNumber() }}</title>
    <style>
        @page { size: {{ $widthMm }} auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #f1f1f1; font-family: {{ $isThermal ? "'Courier New', monospace" : 'system-ui, sans-serif' }}; color: #000; }
        .ticket { width: {{ $widthMm }}; background: #fff; margin: 12px auto; padding: 6mm 4mm; {{ $isThermal ? 'font-size: 10px; line-height: 1.25;' : 'font-size: 12px; padding: 12mm;' }} }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .upper { text-transform: uppercase; }
        .row { display: flex; justify-content: space-between; gap: 4px; }
        .sep { border-top: 1px dashed #888; margin: 4px 0; }
        .small { font-size: {{ $isThermal ? '9px' : '10px' }}; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1px 2px; vertical-align: top; }
        thead th { border-bottom: 1px dashed #888; text-align: left; font-size: {{ $isThermal ? '9px' : '10px' }}; }
        thead th.right { text-align: right; }
        .qr-placeholder { width: 60px; height: 60px; border: 1px solid #999; display: inline-flex; align-items: center; justify-content: center; font-size: 8px; color: #999; margin: 4px auto; }
        @media print {
            body { background: #fff; }
            .ticket { margin: 0; box-shadow: none; }
            .no-print { display: none !important; }
        }
        .actions { max-width: 360px; margin: 8px auto; display: flex; gap: 8px; justify-content: center; }
        .actions button { padding: 8px 16px; border: 1px solid #ccc; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; }
        .actions button.primary { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    </style>
</head>
<body>
    <div class="no-print actions">
        <button onclick="window.print()" class="primary">Imprimir</button>
        <button onclick="window.close()">Cerrar</button>
    </div>

    <div class="ticket">
        {{-- HEADER --}}
        <div class="center">
            @if ($get($h, 'show_business_name'))
                <div class="bold upper">{{ $company->name ?: 'Empresa' }}</div>
            @endif
            @if ($get($h, 'show_legal_name') && $company->legal_name && $company->legal_name !== $company->name)
                <div>{{ $company->legal_name }}</div>
            @endif
            @if ($get($h, 'show_nit'))
                <div>NIT {{ $company->fullNit() }}</div>
            @endif
            @if ($get($h, 'show_address') && $company->address)
                <div>{{ $company->address }}</div>
            @endif
            @if ($get($h, 'show_phone') && $company->phone)
                <div>Tel: {{ $company->phone }}</div>
            @endif
            @if ($get($h, 'show_email', false) && $company->email)
                <div>{{ $company->email }}</div>
            @endif
            @if ($get($h, 'show_location_name') && $location)
                <div class="small">Sede: {{ $location->fullName() }}</div>
            @endif
        </div>

        <div class="sep"></div>

        <div class="center">
            <div class="bold upper">Factura de venta</div>
            <div class="bold">{{ $invoice->fullNumber() }}</div>
            <div class="small">{{ $invoice->date?->format('Y-m-d') }} · {{ $invoice->created_at?->format('H:i') }}</div>
        </div>

        {{-- CLIENTE --}}
        @if ($get($c, 'show'))
            <div class="sep"></div>
            <div>
                <div class="bold">CLIENTE</div>
                @if ($get($c, 'show_name'))
                    <div>{{ $customer?->name ?? '—' }}</div>
                @endif
                @if ($get($c, 'show_document'))
                    <div class="small">{{ strtoupper($customer?->document_type ?? '') }} {{ $customer?->document_number ?? '—' }}</div>
                @endif
                @if ($get($c, 'show_address', false) && $customer?->address)
                    <div class="small">{{ $customer->address }}</div>
                @endif
                @if ($get($c, 'show_phone', false) && ($customer?->phone || $customer?->mobile))
                    <div class="small">Tel: {{ $customer->phone ?: $customer->mobile }}</div>
                @endif
                @if ($get($c, 'show_email', false) && $customer?->email)
                    <div class="small">{{ $customer->email }}</div>
                @endif
            </div>
        @endif

        {{-- LÍNEAS --}}
        <div class="sep"></div>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    @if ($get($l, 'show_quantity'))
                        <th class="right">Cant</th>
                    @endif
                    @if ($get($l, 'show_unit_price'))
                        <th class="right">Precio</th>
                    @endif
                    @if ($get($l, 'show_total'))
                        <th class="right">Total</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td>
                            @if ($get($l, 'show_code') && $line->product?->code)
                                <div class="small">{{ $line->product->code }}</div>
                            @endif
                            <div>{{ $line->description }}</div>
                            @if ($get($l, 'show_discount') && (float) $line->discount_amount > 0)
                                <div class="small">Dto: −${{ number_format((float) $line->discount_amount, 0, ',', '.') }}</div>
                            @endif
                        </td>
                        @if ($get($l, 'show_quantity'))
                            <td class="right">{{ rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.') }}</td>
                        @endif
                        @if ($get($l, 'show_unit_price'))
                            <td class="right">${{ number_format((float) $line->unit_price, 0, ',', '.') }}</td>
                        @endif
                        @if ($get($l, 'show_total'))
                            <td class="right bold">${{ number_format((float) $line->total, 0, ',', '.') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- TOTALES --}}
        <div class="sep"></div>
        @if ($get($t, 'show_subtotal'))
            <div class="row"><span>Subtotal</span><span>${{ number_format((float) $invoice->subtotal, 0, ',', '.') }}</span></div>
        @endif
        @if ($get($t, 'show_discount') && (float) $invoice->discount_total > 0)
            <div class="row"><span>Descuento</span><span>−${{ number_format((float) $invoice->discount_total, 0, ',', '.') }}</span></div>
        @endif
        @if ($get($t, 'show_tax_breakdown') && (float) $invoice->tax_total > 0)
            <div class="row"><span>IVA</span><span>${{ number_format((float) $invoice->tax_total, 0, ',', '.') }}</span></div>
        @endif
        @if ((float) ($invoice->retention_total ?? 0) > 0)
            <div class="row"><span>Retenciones</span><span>−${{ number_format((float) $invoice->retention_total, 0, ',', '.') }}</span></div>
        @endif
        @if ($get($t, 'show_total'))
            <div class="row bold" style="font-size: {{ $isThermal ? '12px' : '15px' }}; margin-top: 2px; padding-top: 2px; border-top: 1px solid #000;">
                <span>TOTAL</span><span>${{ number_format((float) ($invoice->net_payable ?: $invoice->total), 0, ',', '.') }}</span>
            </div>
        @endif

        {{-- PAGOS --}}
        @if ($invoice->payments->isNotEmpty())
            <div class="sep"></div>
            @foreach ($invoice->payments as $pay)
                <div class="row">
                    <span>{{ \App\Models\Payment::PAYMENT_METHODS[$pay->payment_method] ?? $pay->payment_method }}</span>
                    <span>${{ number_format((float) $pay->amount, 0, ',', '.') }}</span>
                </div>
            @endforeach
            @if ($get($t, 'show_paid'))
                <div class="row bold"><span>Pagado</span><span>${{ number_format($totalPaid, 0, ',', '.') }}</span></div>
            @endif
        @endif

        {{-- FOOTER --}}
        <div class="sep"></div>
        <div class="center">
            @if ($get($f, 'show_seller') && $seller)
                <div class="small">Atendido por: {{ $seller->name ?: $seller->email }}</div>
            @endif
            @if ($get($f, 'show_qr_dian'))
                @if ($invoice->cufe && $invoice->qr_url)
                    {{-- QR generado vía servicio externo (sin dependencias en backend). En producción podríamos generar localmente con phpqrcode o similar. --}}
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data={{ urlencode($invoice->qr_url) }}"
                         alt="QR DIAN" style="width: 90px; height: 90px; margin: 4px auto;" />
                    <div class="small" style="word-break: break-all;">CUFE: {{ substr($invoice->cufe, 0, 32) }}…</div>
                @else
                    <div class="qr-placeholder">[ QR DIAN ]</div>
                    <div class="small">Pendiente envío DIAN</div>
                @endif
            @endif
            @if ($get($f, 'show_resolution_info'))
                <div class="small">
                    @if ($invoice->isDianAccepted())
                        Documento autorizado por la DIAN.
                    @else
                        Documento POS — pendiente validación DIAN.
                    @endif
                </div>
            @endif
            @if ($get($f, 'show_thanks'))
                <div class="bold" style="margin-top: 4px;">¡Gracias por tu compra!</div>
            @endif
            @if (! empty($footerText))
                <div class="small" style="margin-top: 4px; white-space: pre-line;">{{ $footerText }}</div>
            @endif
        </div>
    </div>

    <script>
        // Auto-print 200ms después de cargar — da tiempo a que aplique CSS print.
        window.addEventListener('load', () => setTimeout(() => window.print(), 200));
    </script>
</body>
</html>
