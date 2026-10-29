@php
    /** @var \App\Models\Hotel\Stay $stay */
    /** @var \App\Models\Company $company */
    /** @var \App\Models\Hotel\Hotel $hotel */
    /** @var \Illuminate\Support\Collection $guests */

    use App\Models\Hotel\Guest;
    use App\Support\ClockFormat;

    $plata = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');

    // Si no se registró a nadie, la tarjeta igual se imprime: se llena a mano
    // y se digita después. Vale más tenerla firmada que esperar a que alguien
    // termine de digitar con el huésped de pie.
    $filas = $guests->isNotEmpty() ? $guests : collect([null]);

    // Siempre al menos cuatro renglones: una habitación familiar llega con
    // acompañantes que no están en el sistema, y la tarjeta tiene que dejar
    // dónde escribirlos.
    while ($filas->count() < 4) {
        $filas->push(null);
    }
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tarjeta de registro {{ $stay->fullNumber() }}</title>
    <style>
        /* Tamaño carta: esta hoja se firma y se archiva, no se imprime en la
           térmica. */
        @page { size: letter portrait; margin: 14mm; }

        * { box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            background: #fff;
            font-size: 11px;
            line-height: 1.4;
            margin: 0;
        }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .head h1 { font-size: 16px; margin: 0 0 2px; text-transform: uppercase; }
        .head .legal { font-size: 10px; color: #333; }
        .doc-title {
            text-align: right; font-size: 13px; font-weight: 700; text-transform: uppercase;
            border: 2px solid #000; padding: 6px 10px; white-space: nowrap;
        }

        hr { border: 0; border-top: 2px solid #000; margin: 10px 0; }

        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; margin-bottom: 10px; }
        .cell { border: 1px solid #000; border-right: 0; padding: 4px 6px; }
        .cell:last-child { border-right: 1px solid #000; }
        .cell + .cell { }
        .cell .k { font-size: 8.5px; text-transform: uppercase; letter-spacing: .03em; color: #333; }
        .cell .v { font-size: 12px; font-weight: 700; min-height: 15px; }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: span 4; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #000; padding: 4px 5px; font-size: 10px; text-align: left; }
        th { background: #eee; font-size: 8.5px; text-transform: uppercase; letter-spacing: .02em; }
        td { height: 22px; }

        .section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            margin: 12px 0 5px; border-bottom: 1px solid #000; padding-bottom: 2px;
        }

        .terms { font-size: 9px; color: #222; text-align: justify; line-height: 1.35; }

        .signs { display: flex; gap: 30px; margin-top: 26px; }
        .sign { flex: 1; text-align: center; }
        .sign .line { border-top: 1px solid #000; margin-bottom: 3px; }
        .sign .label { font-size: 9px; text-transform: uppercase; }

        .screen-only { margin: 16px 0; text-align: center; }
        @media print { .screen-only { display: none !important; } }

        button {
            padding: 10px 18px; font-size: 14px; font-weight: 700;
            border: 0; border-radius: 8px; background: #4f46e5; color: #fff; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="head">
        <div>
            <h1>{{ $hotel->name ?: $company->name }}</h1>
            <div class="legal">
                NIT {{ $company->nit }}{{ $company->dv ? '-'.$company->dv : '' }}
                @if ($hotel->rnt) · RNT {{ $hotel->rnt }} @endif
            </div>
            <div class="legal">
                {{ $hotel->address ?: $company->address }}
                @if ($hotel->city ?: $company->city) · {{ $hotel->city ?: $company->city }} @endif
                @if ($hotel->phone ?: $company->phone) · Tel. {{ $hotel->phone ?: $company->phone }} @endif
            </div>
        </div>
        <div class="doc-title">
            Tarjeta de registro<br>
            <span style="font-size:11px; font-weight:400;">Folio {{ $stay->fullNumber() }}</span>
        </div>
    </div>

    <hr>

    {{-- Datos de la estadía --}}
    <div class="grid">
        <div class="cell">
            <div class="k">Habitación</div>
            <div class="v">{{ $stay->room?->number ?? '—' }}</div>
        </div>
        <div class="cell">
            <div class="k">Tipo</div>
            <div class="v">{{ $stay->roomType?->name ?? '—' }}</div>
        </div>
        <div class="cell">
            <div class="k">Entrada</div>
            <div class="v">{{ $stay->check_in_at->format(ClockFormat::datetime()) }}</div>
        </div>
        <div class="cell">
            <div class="k">Salida prevista</div>
            <div class="v">{{ $stay->expected_check_out->format('d/m/Y') }} {{ $hotel->checkOutTime() }}</div>
        </div>

        <div class="cell">
            <div class="k">Noches</div>
            <div class="v">{{ $stay->nights }}</div>
        </div>
        <div class="cell">
            <div class="k">Personas</div>
            <div class="v">{{ $stay->adults }} ad.{{ $stay->children > 0 ? ' / '.$stay->children.' niños' : '' }}</div>
        </div>
        <div class="cell">
            <div class="k">Tarifa por noche</div>
            <div class="v">{{ $plata($stay->rate_per_night) }}</div>
        </div>
        <div class="cell">
            <div class="k">Responsable del pago</div>
            <div class="v">{{ $stay->customer?->name ?? '—' }}</div>
        </div>
    </div>

    {{-- Huéspedes. Todos los que duermen ahí, no solo el que paga: eso es lo
         que pide el registro hotelero. --}}
    <div class="section-title">Huéspedes</div>
    <table>
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:22%">Apellidos y nombres</th>
                <th style="width:14%">Documento</th>
                <th style="width:11%">Nacionalidad</th>
                <th style="width:10%">Nacimiento</th>
                <th style="width:13%">Procedencia</th>
                <th style="width:13%">Destino</th>
                <th style="width:13%">Motivo del viaje</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $i => $huesped)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        {{ $huesped?->nombreCompleto() }}
                        @if ($huesped && $huesped->id === $stay->primary_guest_id)
                            <strong>(titular)</strong>
                        @endif
                    </td>
                    <td>{{ $huesped && $huesped->document_number ? $huesped->documento() : '' }}</td>
                    <td>{{ $huesped?->nationality }}</td>
                    <td>{{ $huesped?->birth_date?->format('d/m/Y') }}</td>
                    <td>{{ $huesped?->origin }}</td>
                    <td>{{ $huesped?->destination }}</td>
                    <td>{{ $huesped?->travel_purpose ? (Guest::PURPOSES[$huesped->travel_purpose] ?? '') : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Contacto del titular --}}
    <div class="grid">
        <div class="cell span-2">
            <div class="k">Dirección de residencia</div>
            <div class="v">{{ $stay->primaryGuest?->address }}</div>
        </div>
        <div class="cell">
            <div class="k">Teléfono</div>
            <div class="v">{{ $stay->primaryGuest?->phone }}</div>
        </div>
        <div class="cell">
            <div class="k">Correo</div>
            <div class="v">{{ $stay->primaryGuest?->email }}</div>
        </div>
        <div class="cell span-2">
            <div class="k">Vehículo / placa (si aplica)</div>
            <div class="v"></div>
        </div>
        <div class="cell span-2">
            <div class="k">En caso de emergencia, avisar a</div>
            <div class="v"></div>
        </div>
    </div>

    <div class="section-title">Declaración y autorización</div>
    <p class="terms">
        Declaro que los datos consignados en esta tarjeta son veraces y que las personas relacionadas son
        quienes efectivamente se alojan en la habitación asignada. Autorizo al establecimiento a tratar mis
        datos personales con la finalidad de gestionar el alojamiento, los servicios asociados y el cobro
        correspondiente, así como para atender los requerimientos de información de las autoridades
        competentes, de acuerdo con la Ley 1581 de 2012 y la política de tratamiento de datos del
        establecimiento. Manifiesto conocer y aceptar las condiciones de la estadía, incluidas la hora de
        salida de las {{ $hotel->checkOutTime() }} y la responsabilidad por los daños o faltantes que se
        presenten en la habitación y sus elementos durante mi permanencia.
    </p>

    @if ($stay->tax_exempt)
        <p class="terms">
            <strong>Exención de IVA:</strong> el servicio de alojamiento se factura como exento conforme al
            artículo 481 del Estatuto Tributario, aplicable al turista extranjero no residente en Colombia.
            Sustento: {{ $stay->tax_exempt_reason }}. Los consumos y servicios distintos del alojamiento
            se facturan con el impuesto que les corresponda.
        </p>
    @endif

    <div class="signs">
        <div class="sign">
            <div class="line"></div>
            <div class="label">Firma del huésped titular</div>
        </div>
        <div class="sign">
            <div class="line"></div>
            <div class="label">Documento de identidad</div>
        </div>
        <div class="sign">
            <div class="line"></div>
            <div class="label">Recepción</div>
        </div>
    </div>

    <div class="screen-only">
        <button type="button" onclick="window.print()">🖨️ Imprimir tarjeta de registro</button>
    </div>
</body>
</html>
