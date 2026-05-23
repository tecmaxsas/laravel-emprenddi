{{-- Comprobante de recepción de garantía. HTML imprimible: el usuario
     abre desde el panel y usa Ctrl+P / botón "Imprimir" para entregar
     una constancia al cliente al recibir el equipo. --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Garantía {{ $warranty->fullNumber() }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('logos/favicon_emprenddi.svg') }}">
    <style>
        @page { size: A5; margin: 12mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #f1f5f9; font-family: system-ui, sans-serif; color: #0f172a; }
        .sheet {
            width: 148mm; min-height: 200mm; padding: 14mm; margin: 8mm auto;
            background: #fff; box-shadow: 0 8px 22px -10px rgba(15,23,42,.25);
        }
        h1 { font-size: 16px; margin: 0; letter-spacing: .02em; text-transform: uppercase; }
        h2 { font-size: 11px; margin: 0; color: #6b7280; font-weight: 500; }
        .hdr { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #6366f1; padding-bottom: 10px; margin-bottom: 14px; }
        .hdr-right { text-align: right; }
        .hdr-logo { max-height: 22mm; max-width: 60mm; object-fit: contain; }
        .nro {
            display: inline-block; padding: 4px 10px; border-radius: 6px;
            background: #6366f1; color: #fff; font-weight: 700; font-size: 13px; letter-spacing: .03em;
        }
        .sec { margin-top: 14px; }
        .sec-h {
            font-size: 11px; font-weight: 700; text-transform: uppercase; color: #6b7280;
            letter-spacing: .06em; margin-bottom: 6px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 3px;
        }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 18px; font-size: 11.5px; }
        .grid2 .l { color: #6b7280; }
        .grid2 .v { font-weight: 600; }
        .body-text { font-size: 12px; line-height: 1.5; color: #1e293b; padding: 8px 10px; background: #f8fafc; border-left: 3px solid #fbbf24; border-radius: 4px; }
        .terms { font-size: 9.5px; color: #6b7280; margin-top: 22px; line-height: 1.5; padding-top: 10px; border-top: 1px solid #e5e7eb; }
        .sign {
            display: grid; grid-template-columns: 1fr 1fr; gap: 22px;
            margin-top: 26px; font-size: 10px; color: #6b7280; text-align: center;
        }
        .sign-line {
            border-top: 1px solid #94a3b8; padding-top: 4px; margin-top: 30px;
        }
        .actions { max-width: 360px; margin: 8px auto; display: flex; gap: 8px; justify-content: center; }
        .actions button { padding: 8px 16px; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; }
        .actions button.primary { background: #6366f1; color: #fff; border-color: #6366f1; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print actions">
        <button onclick="window.print()" class="primary">Imprimir</button>
        <button onclick="window.close()">Cerrar</button>
    </div>

    <div class="sheet">
        <div class="hdr">
            <div>
                @if ($company->logo_path)
                    <img src="{{ asset('storage/'.$company->logo_path) }}" alt="{{ $company->name }}" class="hdr-logo">
                @else
                    <h1>{{ $company->name }}</h1>
                @endif
                <h2 style="margin-top:4px;">{{ $company->legal_name ?: $company->name }} · NIT {{ $company->fullNit() }}</h2>
                @if ($company->address)
                    <h2>{{ $company->address }}{{ $company->phone ? ' · Tel: '.$company->phone : '' }}</h2>
                @endif
            </div>
            <div class="hdr-right">
                <div style="font-size:10px; color:#6b7280; margin-bottom:4px;">COMPROBANTE DE RECEPCIÓN</div>
                <span class="nro">GARANTÍA {{ $warranty->fullNumber() }}</span>
                @if ($warranty->rma_number)
                    <div style="font-size:10.5px; color:#6b7280; margin-top:6px;">RMA: <strong>{{ $warranty->rma_number }}</strong></div>
                @endif
                <div style="font-size:10.5px; color:#6b7280; margin-top:4px;">Recibido: {{ $warranty->claim_date?->format('d/m/Y') }}</div>
            </div>
        </div>

        <div class="sec">
            <div class="sec-h">Cliente</div>
            <div class="grid2">
                <div><span class="l">Nombre:</span> <span class="v">{{ $warranty->customer->name }}</span></div>
                <div><span class="l">Documento:</span> <span class="v">{{ strtoupper($warranty->customer->document_type ?? '') }} {{ $warranty->customer->document_number }}</span></div>
                @if ($warranty->customer->phone)
                    <div><span class="l">Teléfono:</span> <span class="v">{{ $warranty->customer->phone }}</span></div>
                @endif
                @if ($warranty->customer->email)
                    <div><span class="l">Email:</span> <span class="v">{{ $warranty->customer->email }}</span></div>
                @endif
            </div>
        </div>

        <div class="sec">
            <div class="sec-h">Equipo recibido</div>
            <div class="grid2">
                <div style="grid-column: span 2;"><span class="l">Producto:</span> <span class="v">{{ $warranty->product->name }}</span></div>
                <div><span class="l">SKU:</span> <span class="v">{{ $warranty->product->code }}</span></div>
                @if ($warranty->serial)
                    <div><span class="l">N° de serie:</span> <span class="v" style="font-family:monospace;">{{ $warranty->serial->serial_number }}</span></div>
                @endif
                @if ($warranty->saleInvoice)
                    <div><span class="l">Factura de venta:</span> <span class="v">{{ $warranty->saleInvoice->fullNumber() }} ({{ $warranty->saleInvoice->date?->format('d/m/Y') }})</span></div>
                @endif
                @if ($warranty->expiration_date)
                    <div><span class="l">Vence garantía:</span> <span class="v">{{ $warranty->expiration_date->format('d/m/Y') }}</span></div>
                @endif
                @if ($warranty->location)
                    <div><span class="l">Sede recibe:</span> <span class="v">{{ $warranty->location->name }}</span></div>
                @endif
            </div>
        </div>

        <div class="sec">
            <div class="sec-h">Problema reportado por el cliente</div>
            <div class="body-text">{{ $warranty->reason }}</div>
        </div>

        <div class="sec">
            <div class="sec-h">Condiciones del servicio</div>
            <div class="terms">
                <strong>1. Diagnóstico:</strong> al recibir el equipo se generará un diagnóstico técnico. El resultado se comunicará al cliente antes de cualquier intervención mayor.<br>
                <strong>2. Garantía:</strong> la cobertura aplica únicamente sobre defectos de fabricación. Daños por mal uso, golpes, líquidos, alteraciones o intervenciones de terceros invalidan la garantía.<br>
                <strong>3. Tiempos:</strong> el tiempo de respuesta depende de la disponibilidad de repuestos. La empresa no se responsabiliza por demoras del fabricante.<br>
                <strong>4. Retiro:</strong> el cliente debe presentar este comprobante para retirar el equipo. Pasados 60 días sin retirarlo, la empresa podrá disponer del mismo conforme a la ley.<br>
                <strong>5. Datos:</strong> respalda tu información antes de entregar el equipo. La empresa no se hace responsable por pérdida de datos durante el proceso de reparación.
            </div>
        </div>

        <div class="sign">
            <div>
                <div class="sign-line">Recibe (técnico/asesor)</div>
                <div style="margin-top:3px;">{{ $warranty->receivedByUser->name ?? '' }}</div>
            </div>
            <div>
                <div class="sign-line">Cliente (acepta condiciones)</div>
                <div style="margin-top:3px;">{{ $warranty->customer->name }}</div>
            </div>
        </div>
    </div>
</body>
</html>
