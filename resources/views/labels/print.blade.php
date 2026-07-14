@php
    $w = $config['width_mm'];
    $h = $config['height_mm'];
    $cols = $config['columns_per_sheet'];
    $fields = $config['fields'];
    $barcodeType = $config['barcode_type'];
    $showCurrency = $config['show_currency_symbol'];
    $mode = $config['print_mode'] ?? 'sheet'; // sheet | roll
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

    // Barcode height: mas espacio en rollo, mas compacto en hoja
    $barcodeHeight = $mode === 'roll' ? max(28, min(60, (int) ($h * 0.5))) : 28;
    $barcodeWidth = $mode === 'roll' ? 1.6 : 1.2;
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
        .toolbar .info .mode-badge {
            display: inline-block; padding: 1px 8px; border-radius: 4px;
            background: {{ $mode === 'roll' ? '#7c3aed' : '#4f46e5' }}; color: #fff;
            font-weight: 700; font-size: 10px; margin-left: 4px; letter-spacing: .04em;
        }
        .toolbar .tip {
            background: #fffbeb; color: #78350f; padding: 6px 10px; border-radius: 6px;
            font-size: 11.5px; border: 1px solid #f59e0b; max-width: 640px;
        }
        .toolbar button {
            padding: 10px 20px; background: #6366f1; color: #fff; border: 0; border-radius: 8px;
            font-weight: 700; cursor: pointer; font-size: 13px;
        }
        .toolbar button:hover { background: #4f46e5; }

        /* ============ MODO SHEET (A4 con grilla) ============ */
        @if ($mode === 'sheet')
            .sheet {
                max-width: 1100px; margin: 0 auto; background: #fff; padding: 12px;
                box-shadow: 0 8px 24px -8px rgba(0,0,0,.2); border-radius: 6px;
                display: grid;
                grid-template-columns: repeat({{ $cols }}, {{ $w }}mm);
                gap: 4mm;
                justify-content: center;
            }
        @else
        /* ============ MODO ROLL (impresora térmica) ============ */
            .sheet {
                max-width: {{ $w * 3 }}mm; margin: 0 auto; background: transparent;
                display: flex; flex-direction: column; gap: 8px;
            }
        @endif

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
            background: #fff;
        }
        @if ($mode === 'roll')
            .label { margin: 0 auto; box-shadow: 0 2px 6px rgba(0,0,0,.08); }
        @endif

        .label .company { font-size: 6pt; color: #64748b; text-align: center; text-transform: uppercase; letter-spacing: .05em; line-height: 1.1; }
        .label .name { font-weight: 700; font-size: 8pt; line-height: 1.15; word-break: break-word; text-align: center; }
        .label .meta { font-size: 6.5pt; color: #475569; text-align: center; line-height: 1.15; }
        .label .code { font-family: ui-monospace, monospace; font-size: 7pt; text-align: center; color: #334155; }
        .label .price { font-weight: 900; font-size: {{ $mode === 'roll' ? '14pt' : '12pt' }}; text-align: center; color: #16a34a; line-height: 1; }
        .label .barcode-wrap { display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .label .barcode-wrap svg { max-width: 100%; height: auto; }
        .label .location { font-size: 6.5pt; color: #475569; text-align: center; font-style: italic; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .label { border: 0; box-shadow: none; }

            /* Fuerza que los colores impriman tal cual (algunos browsers los
               omiten en modo economico) — clave para etiquetas con color. */
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

            @if ($mode === 'sheet')
                .sheet { box-shadow: none; padding: 0; margin: 0; max-width: none; }
                @page { size: A4; margin: 5mm; }
            @else
                /* MODO ROLL: cada etiqueta es UNA pagina con dimensiones
                   exactas y margen 0. El driver de la impresora termica
                   ya define el ancho del rollo — asi 1 etiqueta = 1 page
                   sin corte ni escala. */
                @page { size: {{ $w }}mm {{ $h }}mm; margin: 0; }
                html, body { width: {{ $w }}mm; }
                .sheet { display: block; max-width: none; margin: 0; padding: 0; gap: 0; }
                .label {
                    page-break-after: always;
                    break-after: page;
                    margin: 0;
                    width: {{ $w }}mm;
                    height: {{ $h }}mm;
                }
                .label:last-child { page-break-after: auto; break-after: auto; }
            @endif
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>🏷️ Etiquetas listas para imprimir <span class="mode-badge">{{ $mode === 'roll' ? 'ROLLO' : 'HOJA A4' }}</span></h1>
            <div class="info">{{ count($labels) }} etiqueta(s) · {{ $w }}×{{ $h }} mm · {{ $mode === 'sheet' ? "{$cols} por fila" : '1 por página' }} · {{ $barcodeType }}</div>
            @if ($mode === 'roll')
                <div class="tip" style="margin-top:6px;">
                    <strong>💡 Modo Rollo:</strong> en el diálogo de impresión selecciona tu impresora de etiquetas
                    (Zebra, Brother QL, etc.), <strong>NO cambies el tamaño ni los márgenes</strong>,
                    y desactiva "Ajustar a página" / "Fit to page". Cada etiqueta se envía como una página de {{ $w }}×{{ $h }} mm.
                </div>
            @endif
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
            const height = {{ $barcodeHeight }};
            const width = {{ $barcodeWidth }};
            document.querySelectorAll('.barcode-wrap svg').forEach(function (svg) {
                const value = svg.dataset.value || '';
                if (!value) return;
                try {
                    JsBarcode(svg, value, {
                        format: type,
                        width: width,
                        height: height,
                        displayValue: true,
                        fontSize: 10,
                        margin: 0,
                    });
                } catch (e) {
                    svg.outerHTML = '<span style="font-size:8pt;color:#dc2626;">Código inválido: ' + value + '</span>';
                }
            });
            // Auto-print 500ms tras cargar (da tiempo a JsBarcode)
            setTimeout(function () { window.print(); }, 500);
        })();
    </script>
</body>
</html>
