@php
    $w = $config['width_mm'];
    $h = $config['height_mm'];
    $cols = $config['columns_per_sheet'];
    $fields = $config['fields'];
    $barcodeType = $config['barcode_type'];
    $showCurrency = $config['show_currency_symbol'];
    $currency = $company->currency ?? 'COP';

    // Expandir cada item en $qty etiquetas identicas
    $labels = [];
    foreach ($items as $entry) {
        $p = $entry['product'];
        $barcodeValue = $p->barcode ?: $p->code;
        for ($i = 0; $i < $entry['qty']; $i++) {
            $labels[] = [
                'product' => $p,
                'barcode_value' => $barcodeValue,
            ];
        }
    }

    // Helper: formatear precio
    $fmtPrice = function ($price) use ($currency, $showCurrency) {
        if ($price === null || $price === '') return '';
        $formatted = number_format((float) $price, 0, ',', '.');
        return $showCurrency ? '$ '.$formatted : $formatted;
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Etiquetas — {{ count($labels) }} — {{ $company->name }}</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #e5e7eb; padding: 20px; }

        .toolbar {
            max-width: 1100px; margin: 0 auto 16px; display: flex; justify-content: space-between;
            align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .toolbar h1 { font-size: 16px; color: #1e293b; }
        .toolbar .info { font-size: 12px; color: #64748b; }
        .toolbar button {
            padding: 10px 20px; background: #6366f1; color: #fff; border: 0; border-radius: 8px;
            font-weight: 700; cursor: pointer; font-size: 13px;
        }
        .toolbar button:hover { background: #4f46e5; }

        .sheet {
            max-width: 1100px; margin: 0 auto; background: #fff; padding: 12px;
            box-shadow: 0 8px 24px -8px rgba(0,0,0,.2); border-radius: 6px;
            display: grid;
            grid-template-columns: repeat({{ $cols }}, {{ $w }}mm);
            gap: 4mm;
            justify-content: center;
        }
        .label {
            width: {{ $w }}mm;
            height: {{ $h }}mm;
            border: 1px dashed #cbd5e1;
            padding: 2mm;
            display: flex; flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            font-size: 8pt;
            color: #0f172a;
        }
        .label .company { font-size: 6pt; color: #64748b; text-align: center; text-transform: uppercase; letter-spacing: .05em; line-height: 1.1; }
        .label .name { font-weight: 700; font-size: 8pt; line-height: 1.15; word-break: break-word; text-align: center; }
        .label .meta { font-size: 6.5pt; color: #475569; text-align: center; line-height: 1.15; }
        .label .code { font-family: ui-monospace, monospace; font-size: 7pt; text-align: center; color: #334155; }
        .label .price { font-weight: 900; font-size: 12pt; text-align: center; color: #16a34a; line-height: 1; }
        .label .barcode-wrap { display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .label .barcode-wrap svg { max-width: 100%; height: auto; }
        .label .location { font-size: 6.5pt; color: #475569; text-align: center; font-style: italic; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; padding: 0; margin: 0; max-width: none; }
            @page { size: A4; margin: 5mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>🏷️ Etiquetas listas para imprimir</h1>
            <div class="info">{{ count($labels) }} etiqueta(s) · {{ $w }}×{{ $h }} mm · {{ $cols }} por fila · {{ $barcodeType }}</div>
        </div>
        <button onclick="window.print()">🖨️ Imprimir</button>
    </div>

    <div class="sheet">
        @foreach ($labels as $idx => $label)
            @php $p = $label['product']; @endphp
            <div class="label">
                @if (in_array('company_name', $fields, true))
                    <div class="company">{{ $company->name }}</div>
                @endif

                @if (in_array('name', $fields, true))
                    <div class="name">{{ $p->name }}</div>
                @endif

                @if (in_array('brand', $fields, true) || in_array('category', $fields, true))
                    <div class="meta">
                        @if (in_array('brand', $fields, true) && $p->brand){{ $p->brand }}@endif
                        @if (in_array('brand', $fields, true) && $p->brand && in_array('category', $fields, true) && $p->category) · @endif
                        @if (in_array('category', $fields, true) && $p->category){{ $p->category->name }}@endif
                    </div>
                @endif

                @if (in_array('code', $fields, true))
                    <div class="code">SKU: {{ $p->code }}</div>
                @endif

                @if (in_array('barcode', $fields, true))
                    <div class="barcode-wrap">
                        <svg id="bc-{{ $idx }}" data-value="{{ $label['barcode_value'] }}"></svg>
                    </div>
                @endif

                @if (in_array('price', $fields, true))
                    <div class="price">{{ $fmtPrice($p->default_sale_price) }}</div>
                @endif

                @if (in_array('location', $fields, true))
                    <div class="location">{{ $p->physical_location ?? '' }}</div>
                @endif
            </div>
        @endforeach
    </div>

    <script>
        (function () {
            const type = @json($barcodeType);
            document.querySelectorAll('.barcode-wrap svg').forEach(function (svg) {
                const value = svg.dataset.value || '';
                if (!value) return;
                try {
                    JsBarcode(svg, value, {
                        format: type,
                        width: 1.2,
                        height: 30,
                        displayValue: true,
                        fontSize: 10,
                        margin: 0,
                    });
                } catch (e) {
                    svg.outerHTML = '<span style="font-size:8pt;color:#dc2626;">Código inválido: ' + value + '</span>';
                }
            });
            // Auto-print 400ms tras cargar (da tiempo a JsBarcode)
            setTimeout(function () { window.print(); }, 500);
        })();
    </script>
</body>
</html>
