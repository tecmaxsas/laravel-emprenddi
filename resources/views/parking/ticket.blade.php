{{--
    Ticket imprimible de entrada al parqueadero. Tamaño A6/recibo termico
    estandar (~80mm). Incluye QR generado client-side con qrcode-generator
    desde CDN; al escanearlo en el ParkingTerminal, el operario abre la
    modal de salida automaticamente.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket #{{ $session->id }} · {{ $company->name }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; color:#1e293b; line-height:1.4; padding:20px; }
        .ticket {
            background:#fff; width:320px; margin:0 auto;
            padding:18px 22px 22px; border:1px dashed #94a3b8; border-radius:6px;
            box-shadow:0 8px 24px -12px rgba(0,0,0,.2);
        }
        .head { text-align:center; padding-bottom:12px; border-bottom:1px dashed #cbd5e1; }
        .head .logo { max-height:36px; margin-bottom:6px; }
        .head .co { font-size:13px; font-weight:800; color:#0f172a; }
        .head .meta { font-size:10px; color:#64748b; margin-top:1px; }
        .lot { text-align:center; padding:10px 0; border-bottom:1px dashed #cbd5e1; }
        .lot-name { font-size:12px; font-weight:700; color:#475569; }
        .lot-addr { font-size:10px; color:#94a3b8; margin-top:1px; }
        .ticket-num { font-family:'Inter',monospace; font-size:11px; color:#6366f1; font-weight:800; letter-spacing:.1em; }

        .plate { background:#0f172a; color:#fff; padding:14px; text-align:center; margin:14px 0; border-radius:6px; }
        .plate-label { font-size:10px; opacity:.7; text-transform:uppercase; letter-spacing:.15em; }
        .plate-value { font-family:'Inter',ui-monospace, monospace; font-size:32px; font-weight:900; letter-spacing:5px; margin-top:2px; }

        .row { display:flex; justify-content:space-between; padding:5px 0; font-size:12px; }
        .row .lbl { color:#64748b; }
        .row .val { font-weight:700; color:#0f172a; }

        .qr-wrap { text-align:center; margin:14px 0 6px; padding:14px 0; border-top:1px dashed #cbd5e1; border-bottom:1px dashed #cbd5e1; }
        .qr { display:inline-block; }
        .qr-hint { font-size:10px; color:#64748b; margin-top:6px; }
        .qr-code { font-family:ui-monospace, monospace; font-size:11px; font-weight:800; color:#6366f1; margin-top:4px; letter-spacing:.08em; }

        .foot { text-align:center; padding-top:10px; }
        .foot p { font-size:9px; color:#94a3b8; line-height:1.5; }

        .toolbar { text-align:center; margin-top:18px; }
        .toolbar button {
            padding:10px 22px; border:0; border-radius:8px; background:#6366f1; color:#fff;
            font-weight:700; cursor:pointer; font-size:13px;
        }
        .toolbar button:hover { background:#4f46e5; }

        /* Impresion en termica: negro puro en todo, sin fondos rellenos y con
           bordes solidos. Los grises salen tenues porque el thermal head
           interpreta color medio como puntos dispersos (efecto "sucio").
           Los bloques con background oscuro sobrecalientan el cabezal y salen
           mas palidos que el texto normal — mejor evitarlos por completo. */
        @media print {
            /* Forzar impresion de colores tal cual (evita "auto-optimizacion" del navegador) */
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

            body { background:#fff !important; padding:0; color:#000 !important; font-weight:600; }
            .ticket { box-shadow:none; border:0; width:80mm; padding:8px 10px; margin:0; }
            .toolbar { display:none; }

            /* Todo negro, todo en negrita — la termica solo entiende "hay
               punto o no hay punto"; los grises se vuelven ruido. */
            .head .co, .head .meta,
            .lot-name, .lot-addr, .ticket-num,
            .row .lbl, .row .val,
            .qr-hint, .qr-code,
            .foot p {
                color:#000 !important;
                font-weight:700 !important;
            }
            .head .meta, .lot-addr, .qr-hint, .foot p { font-weight:600 !important; }

            /* Bordes solidos gruesos en vez de dashed gris — se leen mejor */
            .head, .lot, .qr-wrap { border-color:#000 !important; border-style:solid !important; }
            .head { border-bottom-width:1.5px !important; padding-bottom:8px; }
            .lot { border-bottom-width:1.5px !important; padding:8px 0; }
            .qr-wrap { border-top-width:1.5px !important; border-bottom-width:1.5px !important; padding:10px 0; }

            /* Placa: en pantalla es fondo negro con texto blanco. Para imprimir
               invertimos: fondo blanco + texto negro grande + borde grueso.
               Un fondo negro grande en termica sale palido y desperdicia papel. */
            .plate {
                background:#fff !important;
                color:#000 !important;
                border:2px solid #000 !important;
                padding:10px !important;
                margin:10px 0 !important;
            }
            .plate-label { color:#000 !important; opacity:1 !important; font-weight:700 !important; }
            .plate-value { color:#000 !important; font-weight:900 !important; font-size:34px !important; }

            /* Aumentar un pelo el tamaño base para que se lea sin lupa */
            .row { font-size:13px !important; padding:4px 0 !important; }

            @page { size:80mm auto; margin:0; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="head">
            <img src="{{ asset('logos/logo_emprenddi_sin_fondo_negro.png') }}" class="logo" alt="">
            <div class="co">{{ strtoupper($company->name) }}</div>
            <div class="meta">{{ $company->nit ? 'NIT '.$company->nit : '' }}</div>
            <div class="ticket-num" style="margin-top:6px;">TICKET {{ $ticket_number }}</div>
        </div>

        <div class="lot">
            <div class="lot-name">{{ $session->parkingLot?->name }}</div>
            @if ($session->parkingLot?->address)
                <div class="lot-addr">{{ $session->parkingLot->address }}{{ $session->parkingLot->city ? ' · '.$session->parkingLot->city : '' }}</div>
            @endif
        </div>

        <div class="plate">
            <div class="plate-label">Placa</div>
            <div class="plate-value">{{ $session->plate }}</div>
        </div>

        <div class="row"><span class="lbl">Entrada</span><span class="val">{{ $session->entry_at->format('d/m/Y H:i:s') }}</span></div>
        <div class="row"><span class="lbl">Tipo</span><span class="val">{{ $session->vehicleType?->icon }} {{ $session->vehicleType?->name ?? '—' }}</span></div>
        @if ($session->space_code)
            <div class="row"><span class="lbl">Espacio</span><span class="val">{{ $session->space_code }}</span></div>
        @endif
        @if ($session->parkingMembership)
            <div class="row" style="background:#dcfce7; padding:6px 8px; border-radius:5px; margin-top:6px;">
                <span class="lbl" style="color:#166534;">🎟️ Mensualidad</span>
                <span class="val" style="color:#166534;">Cubierto</span>
            </div>
        @endif

        <div class="qr-wrap">
            <div id="qrcode" class="qr"></div>
            <div class="qr-hint">Escanea este código al salir</div>
            <div class="qr-code">{{ $qr_payload }}</div>
        </div>

        <div class="foot">
            <p>Conserve este ticket. Es requerido para la salida del vehículo.<br>
            En caso de pérdida se aplicará tarifa de ticket perdido.</p>
        </div>
    </div>

    <div class="toolbar">
        <button onclick="window.print()">🖨️ Imprimir</button>
    </div>

    <script>
        // Genera el QR con tamaño optimizado para impresion termica
        (function () {
            var typeNumber = 0; // auto
            var errorCorrectionLevel = 'M';
            var qr = qrcode(typeNumber, errorCorrectionLevel);
            qr.addData('{{ $qr_payload }}');
            qr.make();
            document.getElementById('qrcode').innerHTML = qr.createSvgTag({
                cellSize: 5, margin: 0, scalable: false,
            });
            // Imprimir automaticamente al cargar
            setTimeout(function () { window.print(); }, 350);
        })();
    </script>
</body>
</html>
