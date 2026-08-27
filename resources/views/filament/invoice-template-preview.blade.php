@php
    $size = $paper_size ?? 'pos_58';
    $isThermal = in_array($size, ['pos_58', 'pos_80'], true);
    $widthPx = match ($size) {
        'pos_58' => '210px',
        'pos_80' => '290px',
        'letter_half' => '430px',
        'a5' => '480px',
        'letter' => '600px',
        'a4' => '700px',
        default => '290px',
    };

    $settings = is_array($settings ?? null) ? $settings : [];
    $h = $settings['header'] ?? [];
    $c = $settings['customer'] ?? [];
    $l = $settings['lines'] ?? [];
    $t = $settings['totals'] ?? [];
    $f = $settings['footer'] ?? [];

    $get = static fn (array $arr, string $key, $default = true) => array_key_exists($key, $arr) ? (bool) $arr[$key] : $default;

    // Datos de ejemplo
    $items = [
        ['code' => 'P-001', 'barcode' => '7702001000017', 'description' => 'Camisa polo M', 'qty' => 2,    'price' => 45000, 'discount' => 0,    'tax' => 19],
        ['code' => 'P-014', 'barcode' => '7702001000147', 'description' => 'Pantalón jean 32', 'qty' => 1,'price' => 89900, 'discount' => 5000, 'tax' => 19],
        ['code' => 'P-027', 'barcode' => '7702001000277', 'description' => 'Zapatillas urbanas talla 41', 'qty' => 1, 'price' => 145000, 'discount' => 0, 'tax' => 19],
    ];
    $subtotal = 0; $totalDiscount = 0; $totalTax = 0;
    foreach ($items as $it) {
        $line = ($it['price'] - $it['discount']) * $it['qty'];
        $subtotal += $line;
        $totalDiscount += $it['discount'] * $it['qty'];
        $totalTax += round($line * $it['tax'] / (100 + $it['tax']));
    }
    $total = $subtotal;
    $netSubtotal = $subtotal - $totalTax;
    $paid = $total + 5000;
    $change = $paid - $total;
@endphp

<div class="space-y-2">
    <div class="flex items-center justify-between">
        <p class="text-xs font-medium text-gray-700 dark:text-gray-300">Vista previa</p>
        <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ ucfirst(str_replace('_', ' ', $size)) }}</span>
    </div>

    <div class="bg-gray-100 dark:bg-gray-900 rounded p-3 overflow-x-auto">
        <div
            style="width: {{ $widthPx }};"
            class="bg-white text-black mx-auto p-3 border border-gray-300 shadow-sm {{ $isThermal ? 'font-mono text-[11px] leading-tight' : 'text-sm' }}"
        >
            {{-- ENCABEZADO --}}
            <div class="text-center mb-2">
                @if ($get($h, 'show_logo'))
                    <div class="inline-block px-2 py-1 mb-1 border border-gray-300 text-[10px] tracking-wide">[ LOGO ]</div>
                @endif
                @if ($get($h, 'show_business_name'))
                    <div class="font-bold uppercase">Mi Empresa SAS</div>
                @endif
                @if ($get($h, 'show_legal_name'))
                    <div>Razón Social Comercial S.A.S.</div>
                @endif
                @if ($get($h, 'show_nit'))
                    <div>NIT 900.123.456-7</div>
                @endif
                @if ($get($h, 'show_address'))
                    <div>Cra 15 # 80-23, Bogotá D.C.</div>
                @endif
                @if ($get($h, 'show_phone'))
                    <div>Tel: 601 234 5678</div>
                @endif
                @if ($get($h, 'show_email', false))
                    <div>contacto@miempresa.co</div>
                @endif
                @if ($get($h, 'show_location_name'))
                    <div class="mt-1">Sede: BOG-01 — Caja 1</div>
                @endif
                @if ($get($h, 'show_dian_resolution'))
                    <div class="text-[10px] mt-1 leading-tight">Resolución DIAN 18760000001<br>Vigente 2026-01-01 a 2027-12-31<br>Rango 1 a 5,000,000</div>
                @endif
            </div>

            <div class="border-t border-dashed border-gray-400 my-2"></div>

            <div class="text-center mb-2">
                <div class="font-bold tracking-wide">FACTURA ELECTRÓNICA</div>
                <div class="font-bold">SETP-1245</div>
                <div class="text-[10px]">{{ now()->format('Y-m-d H:i:s') }}</div>
            </div>

            {{-- CLIENTE --}}
            @if ($get($c, 'show'))
                <div class="border-t border-dashed border-gray-400 my-2"></div>
                <div class="mb-2">
                    <div class="font-bold">CLIENTE</div>
                    @if ($get($c, 'show_name')) <div>Juan Pérez Gómez</div> @endif
                    @if ($get($c, 'show_document')) <div>CC 1.234.567.890</div> @endif
                    @if ($get($c, 'show_address', false)) <div>Calle 50 # 25-10</div> @endif
                    @if ($get($c, 'show_phone', false)) <div>Tel: 300 555 1234</div> @endif
                    @if ($get($c, 'show_email', false)) <div>juan@email.co</div> @endif
                </div>
            @endif

            {{-- LÍNEAS --}}
            <div class="border-t border-dashed border-gray-400 my-2"></div>
            <div class="mb-2">
                @foreach ($items as $it)
                    @php
                        $lineTotal = ($it['price'] - $it['discount']) * $it['qty'];
                    @endphp
                    <div class="mb-1">
                        @if ($get($l, 'show_code'))
                            <div class="text-[10px] text-gray-600">Cód: {{ $it['code'] }}</div>
                        @endif
                        @if ($get($l, 'show_barcode', false))
                            <div class="text-[10px] text-gray-600">EAN: {{ $it['barcode'] }}</div>
                        @endif
                        @if ($get($l, 'show_description'))
                            <div>{{ $it['description'] }}</div>
                        @endif
                        @if ($get($l, 'show_quantity') || $get($l, 'show_unit_price') || $get($l, 'show_total'))
                            <div class="flex justify-between text-[10px]">
                                <span>
                                    @if ($get($l, 'show_quantity')) {{ $it['qty'] }} × @endif
                                    @if ($get($l, 'show_unit_price')) ${{ number_format($it['price'], 0, ',', '.') }} @endif
                                </span>
                                @if ($get($l, 'show_total'))
                                    <span class="font-bold">${{ number_format($lineTotal, 0, ',', '.') }}</span>
                                @endif
                            </div>
                        @endif
                        @if ($get($l, 'show_discount') && $it['discount'] > 0)
                            <div class="text-[10px] text-gray-600">Dto: -${{ number_format($it['discount'] * $it['qty'], 0, ',', '.') }}</div>
                        @endif
                        @if ($get($l, 'show_tax', false))
                            <div class="text-[10px] text-gray-600">IVA {{ $it['tax'] }}% incluido</div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- TOTALES --}}
            <div class="border-t border-dashed border-gray-400 my-2"></div>
            <div class="mb-2">
                @if ($get($t, 'show_subtotal'))
                    <div class="flex justify-between">
                        <span>Subtotal:</span>
                        <span>${{ number_format($netSubtotal, 0, ',', '.') }}</span>
                    </div>
                @endif
                @if ($get($t, 'show_discount') && $totalDiscount > 0)
                    <div class="flex justify-between">
                        <span>Descuento:</span>
                        <span>-${{ number_format($totalDiscount, 0, ',', '.') }}</span>
                    </div>
                @endif
                @if ($get($t, 'show_tax_breakdown'))
                    <div class="flex justify-between">
                        <span>IVA 19%:</span>
                        <span>${{ number_format($totalTax, 0, ',', '.') }}</span>
                    </div>
                @endif
                @if ($get($t, 'show_total'))
                    <div class="flex justify-between font-bold border-t border-gray-400 pt-1 mt-1">
                        <span>TOTAL:</span>
                        <span>${{ number_format($total, 0, ',', '.') }}</span>
                    </div>
                @endif
                @if ($get($t, 'show_paid'))
                    <div class="flex justify-between mt-1">
                        <span>Pagado (Efectivo):</span>
                        <span>${{ number_format($paid, 0, ',', '.') }}</span>
                    </div>
                @endif
                @if ($get($t, 'show_change'))
                    <div class="flex justify-between">
                        <span>Vuelto:</span>
                        <span>${{ number_format($change, 0, ',', '.') }}</span>
                    </div>
                @endif
            </div>

            {{-- PIE --}}
            <div class="border-t border-dashed border-gray-400 my-2"></div>
            <div class="text-center text-[10px] space-y-1">
                @if ($get($f, 'show_resolution_info'))
                    <div>Autorizada según resolución DIAN.</div>
                @endif
                @if ($get($f, 'show_cufe'))
                    <div class="break-all">CUFE: a8b2c4d6e8f0a2b4c6d8e0f2a4b6c8d0e2f4a6b8</div>
                @endif
                @if ($get($f, 'show_qr_dian'))
                    <div class="my-2 mx-auto w-20 h-20 border border-gray-400 flex items-center justify-center text-gray-500">[ QR DIAN ]</div>
                @endif
                @if ($get($f, 'show_seller'))
                    <div>Atendido por: María L.</div>
                @endif
                @if ($get($f, 'show_thanks'))
                    <div class="mt-2 font-bold">¡Gracias por tu compra!</div>
                @endif
                @if (!empty($footer_text))
                    <div class="mt-2 whitespace-pre-line">{{ $footer_text }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
