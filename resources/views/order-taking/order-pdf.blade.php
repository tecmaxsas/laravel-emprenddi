@php
    /** @var \App\Models\OrderTaking\Order $order */
    /** @var \App\Models\Company $company */

    // El formato es el del formato en Excel que el cliente ya usa con sus
    // compradores. Se respeta hasta en los nombres de las columnas: quien lo
    // recibe lleva anos leyendo ese papel, y un documento "mejorado" que no se
    // parezca al de siempre obliga a volver a explicarlo en cada pedido.
    $plata = fn ($n, $dec = 0) => number_format((float) $n, $dec, ',', '.');

    $cliente = $order->customer;
    $sucursal = $order->branch ?? null;

    // La sucursal manda donde la hay: un cliente con varios puntos se factura
    // al NIT pero se despacha a una direccion concreta.
    $direccion = $sucursal?->address ?: $cliente?->address;
    $ciudad = $sucursal?->city ?: $cliente?->city;
    $correo = $sucursal?->email ?: $cliente?->email;
    $contacto = $sucursal?->contact_person ?: $cliente?->contact_person;
    $horario = $sucursal?->delivery_horario ?: $cliente?->delivery_horario;

    $totalCajas = (float) $order->items->sum('quantity_ordered');

    // El vendedor de la empresa que emite, no el cliente: es a quien se llama
    // si algo del pedido no cuadra.
    $vendedor = $order->seller;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pedido de venta {{ $order->fullNumber() }}</title>
    <style>
        @page { size: A4 portrait; margin: 10mm 9mm; }

        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #000;
            margin: 0;
        }

        /* La franja azul y el titulo gigante son la firma visual del formato:
           es lo primero que el comprador reconoce al recibirlo. */
        .barra { background: #1f3864; height: 9mm; }

        .titulo {
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            letter-spacing: .5px;
            margin: 4px 0 2px;
        }

        table { border-collapse: collapse; }
        .w100 { width: 100%; }

        .fecha-box {
            border: 1px solid #000;
            background: #dce6f1;
            font-weight: bold;
            text-align: center;
            padding: 3px 10px;
            font-size: 10px;
        }

        .etq { font-weight: bold; font-size: 8px; }

        /* Los datos van sobre una linea, no dentro de una caja: asi esta el
           original, y en el impreso se puede escribir encima a mano. */
        .dato {
            border-bottom: 1px solid #000;
            text-align: center;
            padding: 1px 4px;
            font-size: 8px;
        }

        .cliente-nombre {
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            padding-bottom: 2px;
        }

        table.items { width: 100%; }
        table.items th,
        table.items td {
            border: 1px solid #000;
            padding: 2.5px 3px;
            font-size: 7.5px;
        }
        table.items th {
            background: #dce6f1;
            font-weight: bold;
            text-align: center;
            font-size: 7.5px;
        }
        table.items td.c { text-align: center; }
        table.items td.r { text-align: right; font-weight: bold; }
        table.items td.d { text-align: center; }

        /* El numero de fila va por fuera de la rejilla, como en el original. */
        td.fila-num {
            border: 0;
            text-align: right;
            font-weight: bold;
            font-size: 7.5px;
            padding-right: 3px;
            width: 12px;
        }

        .cajas-box {
            background: #dce6f1;
            border: 1px solid #9bb2d4;
            font-weight: bold;
            text-align: center;
            padding: 4px;
            font-size: 10px;
        }

        .tot-etq { text-align: right; font-size: 8.5px; padding: 1.5px 6px; }
        .tot-val { text-align: right; font-size: 8.5px; padding: 1.5px 4px; width: 70px; }
        .tot-ret { background: #dce6f1; }
        .tot-neto { font-size: 12px; font-weight: bold; }

        .enviar {
            background: #dce6f1;
            padding: 6px 8px;
            margin-top: 10px;
            min-height: 16mm;
        }
        .enviar-tit { font-weight: bold; color: #1f3864; font-size: 10px; }

        .pie {
            text-align: center;
            font-size: 7.5px;
            margin-top: 6px;
        }
    </style>
</head>
<body>

    <div class="barra"></div>
    <div class="titulo">PEDIDO DE VENTA</div>

    {{-- FECHA, arriba a la derecha --}}
    <table class="w100">
        <tr>
            <td style="width:72%;"></td>
            <td class="etq" style="text-align:right; padding-right:6px; width:10%;">FECHA</td>
            <td style="width:18%;"><div class="fecha-box">{{ $order->order_date?->format('M.d.Y') ?? '' }}</div></td>
        </tr>
    </table>

    <div style="height:6px;"></div>

    {{-- A quien se le factura. En el original es un numero de sucursal; aqui
         se imprime la sucursal cuando el cliente tiene varias, que es el mismo
         dato con nombre propio. --}}
    <table class="w100">
        <tr>
            <td class="etq" style="width:14%;">Facturar a:</td>
            <td style="font-weight:bold; font-size:8.5px;">{{ $sucursal?->name ?: $order->fullNumber() }}</td>
        </tr>
    </table>

    <div style="height:4px;"></div>

    {{-- Cliente a la izquierda, datos de recibo a la derecha --}}
    <table class="w100">
        <tr>
            <td style="width:46%; vertical-align:top;">
                <div class="cliente-nombre">{{ strtoupper($cliente?->name ?? '') }}</div>
                <table class="w100">
                    <tr>
                        <td class="etq" style="width:30%; border-bottom:1px solid #000; padding:1px 2px;">NIT :</td>
                        <td class="dato">{{ $cliente?->document_number }}{{ $cliente?->dv ? '-'.$cliente->dv : '' }}</td>
                    </tr>
                    <tr>
                        <td class="etq" style="border-bottom:1px solid #000; padding:1px 2px;">Direccion</td>
                        <td class="dato">{{ $direccion ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td class="etq" style="border-bottom:1px solid #000; padding:1px 2px;">Ciudad:</td>
                        <td class="dato">{{ $ciudad ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td class="etq" style="border-bottom:1px solid #000; padding:1px 2px;">Correo:</td>
                        <td class="dato">{{ $correo ?: '-' }}</td>
                    </tr>
                </table>
            </td>

            <td style="width:8%;"></td>

            <td style="width:46%; vertical-align:top;">
                <table class="w100">
                    <tr>
                        <td class="etq" style="width:38%; padding:1px 2px;">CONTACTO</td>
                        <td class="dato">{{ $contacto ?: '-' }}</td>
                    </tr>
                    <tr><td colspan="2" style="height:4px;"></td></tr>
                    <tr>
                        <td class="etq" style="padding:1px 2px;">HORARIO RECIBO</td>
                        <td class="dato">{{ $horario ?: '-' }}</td>
                    </tr>
                    <tr><td colspan="2" style="height:4px;"></td></tr>
                    <tr>
                        <td class="etq" style="padding:1px 2px;">FORMA DE PAGO</td>
                        <td class="dato">{{ $cliente?->payment_terms ?: '-' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="height:8px;"></div>

    {{-- EL CUERPO DEL PEDIDO --}}
    <table class="w100">
        <tr>
            <td style="vertical-align:top;">
                <table class="items">
                    <thead>
                        <tr>
                            <th class="fila-num" style="border:0;"></th>
                            <th style="width:9%;">COD</th>
                            <th style="width:34%;">DESCRIPCION PRODUCTO</th>
                            <th style="width:8%;">PEDIDO<br>CAJAS</th>
                            <th style="width:11%;">VALOR CAJA</th>
                            <th style="width:10%;">IMPUESTO</th>
                            <th style="width:11%;">VALOR CAJA<br>TOTAL</th>
                            <th style="width:15%; border-left:2px solid #000;">COSTO PEDIDO</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $i => $item)
                            @php
                                $cajas = (float) $item->quantity_ordered;
                                $valorCaja = (float) $item->unit_price_before_tax;

                                // El precio con impuesto se toma del precio de
                                // catalogo, no de dividir el impuesto de la
                                // linea. Es lo que hace que una fila con 0
                                // cajas siga mostrando sus precios: el formato
                                // se manda con el catalogo completo y el
                                // comprador escribe cantidades solo donde
                                // necesita. Dividiendo, esas filas saldrian en
                                // cero y el papel dejaria de servir para pedir.
                                $valorCajaTotal = (float) $item->unit_price_at_public;

                                if ($valorCajaTotal <= 0) {
                                    // Sin precio publico guardado se reconstruye:
                                    // del impuesto de la linea si hay cajas, y
                                    // si no, de la tarifa.
                                    $valorCajaTotal = $cajas > 0
                                        ? $valorCaja + ((float) $item->tax_amount) / $cajas
                                        : $valorCaja * (1 + ((float) $item->tax_rate) / 100);
                                }

                                $impuestoCaja = max(0, $valorCajaTotal - $valorCaja);

                                // El costo sale del total guardado, no de
                                // multiplicar las columnas de arriba: si hubo
                                // redondeo, manda lo que de verdad se cobra.
                                $costoPedido = (float) $item->total;
                            @endphp
                            <tr>
                                <td class="fila-num">{{ $i + 1 }}</td>
                                <td class="c">{{ $item->product?->code }}</td>
                                <td class="d">{{ $item->description }}</td>
                                <td class="c">{{ $plata($cajas) }}</td>
                                <td class="r">{{ $plata($valorCaja) }}</td>
                                <td class="r">{{ $plata($impuestoCaja) }}</td>
                                <td class="r">{{ $plata($valorCajaTotal) }}</td>
                                {{-- Una linea sin pedido se deja en guion, como
                                     en el original: el formato se manda con el
                                     catalogo completo y el comprador escribe
                                     cantidades solo donde necesita. --}}
                                <td class="r" style="border-left:2px solid #000;">
                                    {{ $costoPedido > 0 ? $plata($costoPedido) : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <div style="height:10px;"></div>

    {{-- TOTALES: cajas a la izquierda, plata a la derecha --}}
    <table class="w100">
        <tr>
            <td style="width:40%; vertical-align:top;">
                <table class="w100">
                    <tr>
                        <td class="cajas-box" style="width:60%; text-align:right; padding-right:8px;">TOTAL CAJAS</td>
                        <td class="cajas-box" style="width:40%;">{{ $plata($totalCajas) }}</td>
                    </tr>
                </table>
            </td>
            <td style="width:14%;"></td>
            <td style="width:46%; vertical-align:top;">
                <table class="w100">
                    <tr>
                        <td class="tot-etq" style="font-weight:bold;">SUBTOTAL</td>
                        <td class="tot-val" style="text-align:left; width:14px;">$</td>
                        <td class="tot-val" style="font-weight:bold;">{{ $plata($order->total) }}</td>
                    </tr>
                    <tr>
                        <td class="tot-etq">VALOR ANTES IMPUESTOS</td>
                        <td class="tot-val" style="text-align:left;">$</td>
                        <td class="tot-val">{{ $plata($order->subtotal) }}</td>
                    </tr>

                    {{-- Cada retencion con su tarifa: el comprador necesita ver
                         con que porcentaje se le retuvo, no solo cuanto. --}}
                    @forelse ($order->retentions as $ret)
                        <tr>
                            <td class="tot-etq tot-ret" style="font-weight:bold;">
                                {{ strtoupper($ret->tax_name ?: 'RETENCION') }} {{ $ret->tax_code }}
                                {{-- La tarifa COMPLETA, no recortada a dos
                                     decimales: en un 2.514% la cuenta que ve
                                     el cliente no daria. Solo se cambia el
                                     punto por coma, como el resto del
                                     formato. --}}
                                {{ str_replace('.', ',', $ret->rateLabel()) }}%
                            </td>
                            <td class="tot-val tot-ret" style="text-align:left;">$</td>
                            <td class="tot-val tot-ret">{{ $plata($ret->amount, 2) }}</td>
                        </tr>
                    @empty
                        @if ((float) $order->retention_total > 0)
                            <tr>
                                <td class="tot-etq tot-ret" style="font-weight:bold;">RETENCION</td>
                                <td class="tot-val tot-ret" style="text-align:left;">$</td>
                                <td class="tot-val tot-ret">{{ $plata($order->retention_total, 2) }}</td>
                            </tr>
                        @endif
                    @endforelse

                    <tr>
                        <td class="tot-etq tot-neto">VALOR NETO FACTURA</td>
                        <td class="tot-val tot-neto" style="text-align:left;">$</td>
                        <td class="tot-val tot-neto">{{ $plata($order->net_payable ?: $order->total) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ENVIAR: instrucciones de despacho. En el original es un recuadro en
         blanco donde se escribe a mano; aqui se llena con lo que el sistema ya
         sabe y queda espacio para el resto. --}}
    <div class="enviar">
        <div class="enviar-tit">ENVIAR</div>
        @if ($sucursal)
            <div>{{ $sucursal->name }}@if ($sucursal->address) — {{ $sucursal->address }}@endif</div>
        @endif
        @if ($order->delivery_date_expected)
            <div>Entrega esperada: {{ $order->delivery_date_expected->format('d/m/Y') }}</div>
        @endif
        @if ($order->notes)
            <div>{{ $order->notes }}</div>
        @endif
    </div>

    <div class="pie">
        @if ($vendedor)
            <b>Contacto: {{ $vendedor->name }}@if ($company?->name) — Comercial {{ $company->name }}@endif</b>.
            @if ($vendedor->email) Correo: {{ $vendedor->email }}. @endif
        @elseif ($company?->name)
            <b>{{ $company->name }}</b>.
        @endif
        @if ($company?->phone) <b>Corporativo: {{ $company->phone }}</b> @endif
    </div>

</body>
</html>
