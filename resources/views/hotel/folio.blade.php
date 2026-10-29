@php
    /** @var \App\Models\Hotel\Stay $stay */
    /** @var \App\Models\Company $company */
    /** @var \App\Models\Hotel\Hotel $hotel */
    /** @var \Illuminate\Support\Collection $charges */
    /** @var array $config */

    use App\Models\Hotel\StayCharge;
    use App\Support\ClockFormat;

    $plata = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    $facturada = (bool) $stay->sale_invoice_id;

    // Las noches se agrupan igual que en la factura: cinco renglones iguales
    // en un tiquete de 80 mm es un documento que nadie lee. Los consumos van
    // uno por uno, que es donde el huésped discute.
    $noches = $charges->where('kind', StayCharge::KIND_ROOM);
    $consumos = $charges->whereIn('kind', [StayCharge::KIND_PRODUCT, StayCharge::KIND_SERVICE]);
    $ajustes = $charges->where('kind', StayCharge::KIND_ADJUSTMENT);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cuenta {{ $stay->fullNumber() }}</title>
    <style>
        /* 80 mm de ancho útil, como la térmica */
        @page { size: 80mm auto; margin: 3mm; }

        * { box-sizing: border-box; }

        body {
            font-family: "Courier New", Courier, monospace;
            width: 74mm;
            margin: 0 auto;
            padding: 4px 0 12px;
            color: #000;
            background: #fff;
            font-size: 12px;
            line-height: 1.35;
        }

        .center { text-align: center; }
        .bold { font-weight: 700; }
        .sep { border-top: 1px dashed #000; margin: 6px 0; }
        .sep-strong { border-top: 2px solid #000; margin: 6px 0; }

        .company-name { font-size: 15px; font-weight: 700; text-transform: uppercase; }
        .meta { font-size: 11px; }
        .room-label { font-size: 24px; font-weight: 700; margin: 4px 0 2px; }

        /*
         * EL AVISO.
         *
         * Igual que en la precuenta del restaurante: va arriba, con marco, y
         * no es configurable. Una cuenta de hotel sale por la misma impresora
         * que la factura y lleva los mismos totales; si no dice con todas las
         * letras que no es una factura de venta, el huésped se va creyendo que
         * ya la tiene. En Colombia eso es un problema con la DIAN, no un
         * detalle de diseño.
         *
         * Cuando la estadía YA se facturó, el aviso se reemplaza por el número
         * de la factura: ahí sí hay documento y lo que se imprime es el
         * respaldo del detalle.
         */
        .aviso {
            border: 2px solid #000;
            padding: 5px 4px;
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            line-height: 1.25;
            margin: 6px 0;
            text-transform: uppercase;
        }

        .row { display: flex; justify-content: space-between; gap: 6px; }
        .row-total { font-size: 16px; font-weight: 700; }

        .item { margin-bottom: 4px; }
        .item-main { display: flex; justify-content: space-between; gap: 6px; font-weight: 700; }
        .item-qty { min-width: 28px; }
        .item-name { flex: 1; }
        .item-sub { padding-left: 28px; font-size: 10.5px; }

        .block-head {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            margin: 6px 0 3px; border-bottom: 1px solid #000;
        }

        .footer { font-size: 11px; margin-top: 6px; white-space: pre-line; text-align: center; }

        .screen-only { margin: 10px 0; text-align: center; }
        @media print { .screen-only { display: none !important; } }

        button {
            padding: 10px 18px; font-size: 14px; font-weight: 700;
            border: 0; border-radius: 8px; background: #4f46e5; color: #fff; cursor: pointer;
        }
    </style>

    @include('partials.thermal-print')
</head>
<body>
    <div class="center">
        <div class="company-name">{{ $hotel->name ?: $company->name }}</div>
        @if ($company->nit)
            <div class="meta">NIT {{ $company->nit }}{{ $company->dv ? '-'.$company->dv : '' }}</div>
        @endif
        @if ($hotel->address ?: $company->address)
            <div class="meta">{{ $hotel->address ?: $company->address }}</div>
        @endif
        @if ($hotel->phone ?: $company->phone)
            <div class="meta">Tel. {{ $hotel->phone ?: $company->phone }}</div>
        @endif
        @if ($config['show_rnt'] && $hotel->rnt)
            {{-- El RNT es obligatorio exhibirlo para un prestador turístico
                 inscrito, y la cuenta es donde el huésped lo ve. --}}
            <div class="meta">RNT {{ $hotel->rnt }}</div>
        @endif
    </div>

    @if ($facturada)
        <div class="aviso">
            Cuenta del folio {{ $stay->fullNumber() }}<br>
            Factura {{ $stay->invoice?->fullNumber() }}
        </div>
    @else
        <div class="aviso">
            Cuenta de la estadía<br>
            Este documento NO es una factura de venta
        </div>
    @endif

    <div class="center">
        <div class="room-label">HAB. {{ $stay->room?->number ?? '—' }}</div>
        <div class="meta">Folio {{ $stay->fullNumber() }}</div>
        <div class="meta">{{ $stay->nombreDelHuesped() }}</div>
        @if ($stay->customer && $stay->customer->document_number !== '222222222')
            <div class="meta">{{ $stay->customer->name }} · {{ $stay->customer->document_number }}</div>
        @endif
        <div class="meta">{{ $stay->roomType?->name }}</div>
        <div class="meta">
            {{ $stay->adults }} adulto(s){{ $stay->children > 0 ? ' + '.$stay->children.' niño(s)' : '' }}
        </div>
    </div>

    <div class="sep"></div>

    <div class="row"><span>Entrada</span><span>{{ $stay->check_in_at->format(ClockFormat::datetime()) }}</span></div>
    <div class="row">
        <span>Salida</span>
        <span>
            {{ $stay->check_out_at
                ? $stay->check_out_at->format(ClockFormat::datetime())
                : $stay->expected_check_out->format('d/m/Y').' '.$hotel->checkOutTime() }}
        </span>
    </div>
    <div class="row"><span>Noches</span><span>{{ $stay->nights }}</span></div>
    <div class="row"><span>Tarifa / noche</span><span>{{ $plata($stay->rate_per_night) }}</span></div>

    @if ($noches->isNotEmpty())
        <div class="block-head">Alojamiento</div>
        @foreach ($noches as $noche)
            <div class="item">
                <div class="item-main">
                    <span class="item-qty">1x</span>
                    <span class="item-name">Noche {{ $noche->night_date?->format('d/m/Y') }}</span>
                    <span>{{ $plata($noche->total) }}</span>
                </div>
            </div>
        @endforeach
    @endif

    @if ($consumos->isNotEmpty())
        <div class="block-head">Consumos y servicios</div>
        @foreach ($consumos as $cargo)
            <div class="item">
                <div class="item-main">
                    <span class="item-qty">{{ rtrim(rtrim(number_format((float) $cargo->quantity, 2, ',', '.'), '0'), ',') }}x</span>
                    <span class="item-name">{{ $cargo->description }}</span>
                    <span>{{ $plata($cargo->total) }}</span>
                </div>
                <div class="item-sub">{{ $cargo->charge_date?->format('d/m/Y') }}</div>
            </div>
        @endforeach
    @endif

    @if ($ajustes->isNotEmpty())
        <div class="block-head">Ajustes y cortesías</div>
        @foreach ($ajustes as $ajuste)
            <div class="item">
                <div class="item-main">
                    <span class="item-qty"></span>
                    <span class="item-name">{{ $ajuste->description }}</span>
                    <span>{{ $plata($ajuste->total) }}</span>
                </div>
            </div>
        @endforeach
    @endif

    <div class="sep"></div>

    <div class="row"><span>Alojamiento</span><span>{{ $plata($stay->room_total) }}</span></div>

    @if ((float) $stay->consumption_total > 0)
        <div class="row"><span>Consumos</span><span>{{ $plata($stay->consumption_total) }}</span></div>
    @endif

    @if ((float) $stay->discount_total > 0)
        <div class="row"><span>Rebajas</span><span>− {{ $plata($stay->discount_total) }}</span></div>
    @endif

    @if ((float) $stay->tax_total > 0)
        <div class="row"><span>IVA incluido</span><span>{{ $plata($stay->tax_total) }}</span></div>
    @endif

    <div class="sep-strong"></div>
    <div class="row row-total"><span>TOTAL</span><span>{{ $plata($stay->total) }}</span></div>

    @if ((float) $stay->paid_total > 0)
        <div class="row"><span>Pagado</span><span>{{ $plata($stay->paid_total) }}</span></div>
        @if ($stay->saldo() > 0.01)
            <div class="row bold"><span>SALDO</span><span>{{ $plata($stay->saldo()) }}</span></div>
        @endif
    @endif

    @if ($stay->tax_exempt)
        <div class="sep"></div>
        <div class="meta center">
            Alojamiento exento de IVA — art. 481 ET<br>
            {{ $stay->tax_exempt_reason }}
        </div>
    @endif

    @if ($config['folio_footer'] !== '')
        <div class="sep"></div>
        <div class="footer">{{ $config['folio_footer'] }}</div>
    @endif

    <div class="screen-only">
        <button type="button" onclick="window.print()">🖨️ Imprimir cuenta</button>
    </div>

    <script>
        // Se imprime sola: el recepcionista la manda y va camino al mostrador,
        // no a leer la pantalla.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
