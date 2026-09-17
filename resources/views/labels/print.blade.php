@php
    $w = $config['width_mm'];
    $h = $config['height_mm'];
    $cols = $config['columns_per_sheet'];
    $fields = $config['fields'];
    $barcodeType = $config['barcode_type'];
    $showCurrency = $config['show_currency_symbol'];
    $mode = $config['print_mode'] ?? 'sheet'; // sheet | roll
    $currency = $company->currency ?? 'COP';

    // Cuantas etiquetas trae el rollo una al lado de la otra. El de 50 × 25 se
    // consigue mucho de dos a lo ancho; mandando una etiqueta por pagina salia
    // la de la izquierda y la de la derecha en blanco.
    $across = $mode === 'roll' ? max(1, (int) ($config['roll_across'] ?? 1)) : 1;
    $gap = $mode === 'roll' ? max(0, (int) ($config['roll_gap_mm'] ?? 0)) : 0;

    // La pagina en modo rollo no mide una etiqueta: mide el rollo entero.
    $pageW = \App\Support\LabelsSettings::anchoDePagina($w, $across, $gap);

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

    // Alto del codigo de barras.
    //
    // Antes se calculaba como `$h * 0.5` tratando los milimetros como si
    // fueran pixeles: en una etiqueta de 50 mm de alto daba 25 px, que sobre
    // una pagina de 189 px son 7 mm. De ahi que la etiqueta saliera con el
    // codigo minusculo y media etiqueta en blanco.
    // 1 mm = 3.7795 px, y el codigo se lleva el 35% del alto util.
    $altoPx = $h * 3.7795;
    $barcodeHeight = $mode === 'roll'
        ? max(30, min(220, (int) round($altoPx * 0.35)))
        : 28;
    $barcodeWidth = $mode === 'roll' ? 1.6 : 1.2;
    $barcodeFont = $mode === 'roll' ? max(10, min(20, (int) round($altoPx * 0.07))) : 10;

    // Lo que NO se va a poder imprimir. Una etiqueta a la que le falta el
    // codigo de barras es una etiqueta inservible, y hasta ahora salia el
    // hueco en blanco sin explicacion.
    $sinCodigo = 0;
    $sinPrecio = 0;
    foreach ($labels as $l) {
        if (in_array('barcode', $fields, true) && trim((string) $l['barcode_value']) === '') {
            $sinCodigo++;
        }
        // La columna es NOT NULL, asi que un producto sin precio cargado no
        // llega en null: llega en cero. Una etiqueta de estante que diga «$ 0»
        // es peor que una que avise.
        if (in_array('price', $fields, true) && (float) ($l['product']->default_sale_price ?? 0) <= 0) {
            $sinPrecio++;
        }
    }
    $hayFaltantes = $sinCodigo > 0 || $sinPrecio > 0;

    // En rollo las etiquetas se agrupan en filas del ancho del rollo; cada fila
    // es una pagina. En hoja la grilla CSS ya se encarga.
    $filas = $mode === 'roll' ? array_chunk($labels, $across, true) : [$labels];
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
                max-width: {{ max($pageW, 60) * 3 }}mm; margin: 0 auto; background: transparent;
                display: flex; flex-direction: column; gap: 8px;
            }
            /* Una fila = el ancho del rollo = una página. */
            .fila {
                display: flex; justify-content: flex-start; align-items: flex-start;
                width: {{ $pageW }}mm; margin: 0 auto;
                gap: {{ $gap }}mm;
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

        .label .company { font-size: 7pt; color: #64748b; text-align: center; text-transform: uppercase; letter-spacing: .05em; line-height: 1.1; }
        .label .name { font-weight: 700; font-size: 8pt; line-height: 1.15; word-break: break-word; text-align: center; }
        .label .meta { font-size: 7pt; color: #475569; text-align: center; line-height: 1.15; }
        .label .code { font-family: ui-monospace, monospace; font-size: 7.5pt; text-align: center; color: #334155; }
        .label .price { font-weight: 900; font-size: {{ $mode === 'roll' ? '14pt' : '12pt' }}; text-align: center; color: #16a34a; line-height: 1; }
        .label .barcode-wrap { display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .label .barcode-wrap svg { max-width: 100%; height: auto; }
        .label .location { font-size: 7pt; color: #475569; text-align: center; font-style: italic; }

        /* Un dato que falta se dice, no se deja en blanco: quien pega la
           etiqueta tiene que enterarse de que ese producto esta incompleto. */
        .label .falta {
            font-size: 7pt; text-align: center; color: #b91c1c;
            border: 1px dashed currentColor; border-radius: 3px; padding: 1mm 0;
        }

        .toolbar .aviso {
            background: #fef2f2; color: #7f1d1d; border-color: #dc2626;
        }
        .toolbar details { margin-top: 6px; font-size: 11.5px; color: #334155; }
        .toolbar details summary { cursor: pointer; font-weight: 700; }
        .toolbar details table { border-collapse: collapse; margin-top: 6px; background: #fff; }
        .toolbar details th, .toolbar details td {
            border: 1px solid #e2e8f0; padding: 3px 8px; text-align: left; font-size: 11px;
        }
        .toolbar details td.vacio { color: #b91c1c; font-weight: 700; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .label { border: 0; box-shadow: none; }

            /* Fuerza que los colores impriman tal cual (algunos browsers los
               omiten en modo economico). */
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

            /* TODO EN NEGRO PURO.
               Una impresora termica es monocroma: no tiene tinta, quema puntos.
               Un gris como #64748b no lo puede hacer, asi que lo aproxima con un
               patron de puntos disperso y el texto sale desvaido —justo lo que
               pasaba con el nombre de la empresa y el SKU—. El precio en verde
               era peor todavia.
               Y el !important es necesario: sin el, las reglas de arriba ganan
               por especificidad. */
            .label,
            .label .company,
            .label .name,
            .label .meta,
            .label .code,
            .label .price,
            .label .falta,
            .label .location { color: #000 !important; }

            /* Mas cuerpo. A 203 dpi —lo normal en estas impresoras— un trazo
               fino se pierde entre punto y punto. Lo que en pantalla se ve
               elegante, impreso se ve roto. */
            .label .company { font-weight: 700; letter-spacing: .02em; }
            .label .meta,
            .label .code,
            .label .location { font-weight: 600; }
            .label .location { font-style: normal; }

            /* El codigo de barras, sin suavizado: un borde difuminado es lo que
               hace que el lector tenga que intentarlo tres veces.
               NO se le fuerza el color aqui. JsBarcode dibuja un rectangulo de
               FONDO ademas de las barras, y pintar todos los `rect` de negro
               tapaba el codigo entero con un bloque solido. El color va en las
               opciones de JsBarcode, que sabe cual rectangulo es cual. */
            .label .barcode-wrap svg { shape-rendering: crispEdges; }

            @if ($mode === 'sheet')
                .sheet { box-shadow: none; padding: 0; margin: 0; max-width: none; }
                @page { size: A4; margin: 5mm; }
            @else
                /* MODO ROLL: una FILA del rollo es una pagina, con margen 0.
                   Normalmente la fila es una sola etiqueta, pero hay rollos
                   que traen 2 —o mas— a lo ancho: ahi la pagina mide el rollo
                   entero y el corte avanza una fila, no una etiqueta. */
                @page { size: {{ $pageW }}mm {{ $h }}mm; margin: 0; }
                html, body { width: {{ $pageW }}mm; }
                .sheet { display: block; max-width: none; margin: 0; padding: 0; gap: 0; }
                .fila {
                    page-break-after: always;
                    break-after: page;
                    margin: 0;
                    width: {{ $pageW }}mm;
                    height: {{ $h }}mm;
                    gap: {{ $gap }}mm;
                }
                .fila:last-child { page-break-after: auto; break-after: auto; }
                .label {
                    margin: 0;
                    width: {{ $w }}mm;
                    height: {{ $h }}mm;
                    flex: 0 0 {{ $w }}mm;
                }
            @endif
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>🏷️ Etiquetas listas para imprimir <span class="mode-badge">{{ $mode === 'roll' ? 'ROLLO' : 'HOJA A4' }}</span></h1>
            <div class="info">{{ count($labels) }} etiqueta(s) · {{ $w }}×{{ $h }} mm · {{ $mode === 'sheet' ? "{$cols} por fila" : "{$across} por fila del rollo" }} · {{ $barcodeType }}</div>
            @if ($mode === 'roll')
                <div class="tip" style="margin-top:6px;">
                    <strong>💡 Modo Rollo:</strong> en el diálogo de impresión selecciona tu impresora de etiquetas
                    (Zebra, Brother QL, etc.), <strong>NO cambies el tamaño ni los márgenes</strong>,
                    y desactiva "Ajustar a página" / "Fit to page".
                    @if ($across > 1)
                        Tu rollo trae <strong>{{ $across }} etiquetas a lo ancho</strong>, así que cada página
                        es una <strong>fila completa de {{ $pageW }}×{{ $h }} mm</strong> — ese es el tamaño de papel
                        que tiene que estar definido en el driver, no {{ $w }}×{{ $h }}.
                    @else
                        Cada etiqueta se envía como una página de {{ $w }}×{{ $h }} mm.
                    @endif
                </div>
            @endif

            @if ($hayFaltantes)
                <div class="tip aviso" style="margin-top:6px;">
                    <strong>⚠️ Hay datos que no se van a poder imprimir.</strong>
                    @if ($sinCodigo)
                        {{ $sinCodigo }} etiqueta(s) sin código de barras —el producto no tiene ni código de barras ni SKU—.
                    @endif
                    @if ($sinPrecio)
                        {{ $sinPrecio }} etiqueta(s) sin precio —el producto no tiene precio de venta cargado—.
                    @endif
                    Complétalo en la ficha del producto y vuelve a imprimir.
                    <em>No se abrió el diálogo de impresión solo: revisa y dale a Imprimir cuando quieras.</em>
                </div>
            @endif

            {{-- Que se pueda ver exactamente con que datos se armo cada
                 etiqueta. Un hueco en blanco no dice nada; esta tabla si. --}}
            <details>
                <summary>Ver los datos con que se arma cada etiqueta</summary>
                <div style="margin-top:4px;">
                    Campos activos: <strong>{{ implode(', ', array_map(fn ($f) => \App\Support\LabelsSettings::AVAILABLE_FIELDS[$f] ?? $f, $fields)) ?: '(ninguno)' }}</strong>
                </div>
                <table>
                    <tr><th>#</th><th>Producto</th><th>SKU</th><th>Código de barras</th><th>Precio</th></tr>
                    @foreach (array_slice($labels, 0, 10) as $i => $l)
                        @php $lp = $l['product']; @endphp
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $lp->name }}</td>
                            <td class="{{ ($lp->code ?? '') === '' ? 'vacio' : '' }}">{{ $lp->code ?: 'vacío' }}</td>
                            <td class="{{ trim((string) $l['barcode_value']) === '' ? 'vacio' : '' }}">{{ $l['barcode_value'] ?: 'vacío' }}</td>
                            <td class="{{ (float) ($lp->default_sale_price ?? 0) <= 0 ? 'vacio' : '' }}">{{ (float) ($lp->default_sale_price ?? 0) <= 0 ? 'sin precio' : $fmtPrice($lp->default_sale_price) }}</td>
                        </tr>
                    @endforeach
                </table>
                @if (count($labels) > 10)
                    <div style="margin-top:4px;">… y {{ count($labels) - 10 }} más.</div>
                @endif
                <div id="bc-estado" style="margin-top:6px;"></div>
            </details>
        </div>
        <button onclick="window.print()">🖨️ Imprimir</button>
    </div>

    <div class="sheet">
        @foreach ($filas as $fila)
        @if ($mode === 'roll') <div class="fila"> @endif
        @foreach ($fila as $idx => $label)
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
                    @if (trim((string) $label['barcode_value']) !== '')
                        <div class="barcode-wrap">
                            <svg id="bc-{{ $idx }}" data-value="{{ $label['barcode_value'] }}"></svg>
                        </div>
                    @else
                        <div class="falta">Este producto no tiene código de barras ni SKU</div>
                    @endif
                @endif

                @if (in_array('price', $fields, true))
                    @if ((float) ($p->default_sale_price ?? 0) > 0)
                        <div class="price">{{ $fmtPrice($p->default_sale_price) }}</div>
                    @else
                        <div class="falta">Este producto no tiene precio de venta</div>
                    @endif
                @endif

                @if (in_array('location', $fields, true))
                    <div class="location">{{ $p->physical_location ?? '' }}</div>
                @endif
            </div>
        @endforeach
        @if ($mode === 'roll') </div> @endif
        @endforeach
    </div>

    <script>
        (function () {
            const type = @json($barcodeType);
            const height = {{ $barcodeHeight }};
            const width = {{ $barcodeWidth }};
            const estado = document.getElementById('bc-estado');
            const svgs = document.querySelectorAll('.barcode-wrap svg');
            let fallaron = 0;

            // JsBarcode viene de un CDN. Si la tienda esta sin internet o la red
            // lo bloquea, antes salia la etiqueta con el hueco en blanco y nadie
            // se enteraba hasta despues de pegarla en la mercancia.
            if (svgs.length && typeof JsBarcode === 'undefined') {
                svgs.forEach(function (svg) {
                    svg.outerHTML = '<div class="falta">No se pudo cargar el generador de códigos de barras (sin conexión)</div>';
                });
                if (estado) {
                    estado.innerHTML = '<strong style="color:#b91c1c;">JsBarcode no cargó.</strong> '
                        + 'El navegador no pudo bajar la librería del código de barras. '
                        + 'Revisa la conexión a internet de este equipo.';
                }
                return; // sin auto-print: no se imprimen etiquetas sin codigo
            }

            svgs.forEach(function (svg) {
                const value = svg.dataset.value || '';
                try {
                    JsBarcode(svg, value, {
                        format: type,
                        width: width,
                        height: height,
                        displayValue: true,
                        fontSize: {{ $barcodeFont }},
                        margin: 0,
                        // Explicito y no por defecto: una barra gris la termica
                        // la aproxima con puntos y el lector falla.
                        lineColor: '#000000',
                        background: '#ffffff',
                    });
                } catch (e) {
                    fallaron++;
                    // El formato exige algo que el valor no cumple —EAN-13 pide
                    // 12 digitos, por ejemplo— y hay que decir cual es cual.
                    svg.outerHTML = '<div class="falta">«' + value + '» no es un '
                        + type + ' válido</div>';
                }
            });

            if (estado) {
                estado.innerHTML = fallaron
                    ? '<strong style="color:#b91c1c;">' + fallaron + ' código(s) rechazados por el formato ' + type + '.</strong> '
                      + 'CODE128 acepta cualquier texto; EAN-13 exige exactamente 12 dígitos.'
                    : svgs.length + ' código(s) de barras generados en formato ' + type + '.';
            }

            // Si hay algo que no se pudo imprimir, no se abre el dialogo solo:
            // que el usuario lo vea antes de gastar rollo.
            if (fallaron || {{ $hayFaltantes ? 'true' : 'false' }}) return;

            // Auto-print 500ms tras cargar (da tiempo a JsBarcode)
            setTimeout(function () { window.print(); }, 500);
        })();
    </script>
</body>
</html>
