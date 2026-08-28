{{-- Detalle linea por linea de un pedido: que se despacho y que falta.

     No fija colores de fondo ni de texto claros: el modal se pinta sobre el
     tema del usuario, asi que el texto hereda (color:inherit) y los fondos y
     bordes van translucidos, que se ven igual en claro y en oscuro. Solo los
     acentos de despachado/pendiente llevan color propio, elegido para leerse
     sobre cualquiera de los dos fondos. --}}
<div style="overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:12.5px; color:inherit;">
        <thead>
            <tr style="background:rgba(148,163,184,0.16);">
                <th style="text-align:left;  padding:8px 10px; color:inherit; font-weight:700; border-bottom:1px solid rgba(148,163,184,0.35);">Producto</th>
                <th style="text-align:right; padding:8px 10px; color:inherit; font-weight:700; border-bottom:1px solid rgba(148,163,184,0.35); white-space:nowrap;">Pedido</th>
                <th style="text-align:right; padding:8px 10px; color:inherit; font-weight:700; border-bottom:1px solid rgba(148,163,184,0.35); white-space:nowrap;">Despachado</th>
                <th style="text-align:right; padding:8px 10px; color:inherit; font-weight:700; border-bottom:1px solid rgba(148,163,184,0.35); white-space:nowrap;">Pendiente</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lineas as $linea)
                <tr style="border-bottom:1px solid rgba(148,163,184,0.2);">
                    <td style="padding:7px 10px; color:inherit;">{{ $linea['descripcion'] }}</td>
                    <td style="padding:7px 10px; text-align:right; color:inherit; font-variant-numeric:tabular-nums;">
                        {{ number_format($linea['pedido'], 2, ',', '.') }}
                    </td>
                    <td style="padding:7px 10px; text-align:right; color:#16a34a; font-variant-numeric:tabular-nums;">
                        {{ number_format($linea['despachado'], 2, ',', '.') }}
                    </td>
                    <td style="padding:7px 10px; text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:{{ $linea['pendiente'] > 0 ? '#d97706' : '#16a34a' }};">
                        {{ number_format($linea['pendiente'], 2, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="padding:14px 10px; text-align:center; color:inherit; opacity:.6;">
                        Este pedido no tiene líneas.
                    </td>
                </tr>
            @endforelse
        </tbody>

        @if (count($lineas) > 1)
            {{-- Con una sola linea el total repite la fila y estorba. --}}
            @php
                $totPedido = array_sum(array_column($lineas, 'pedido'));
                $totDespachado = array_sum(array_column($lineas, 'despachado'));
                $totPendiente = array_sum(array_column($lineas, 'pendiente'));
            @endphp
            <tfoot>
                <tr style="border-top:2px solid rgba(148,163,184,0.45);">
                    <td style="padding:8px 10px; color:inherit; font-weight:800;">Total</td>
                    <td style="padding:8px 10px; text-align:right; color:inherit; font-weight:800; font-variant-numeric:tabular-nums;">
                        {{ number_format($totPedido, 2, ',', '.') }}
                    </td>
                    <td style="padding:8px 10px; text-align:right; color:#16a34a; font-weight:800; font-variant-numeric:tabular-nums;">
                        {{ number_format($totDespachado, 2, ',', '.') }}
                    </td>
                    <td style="padding:8px 10px; text-align:right; font-weight:800; font-variant-numeric:tabular-nums; color:{{ $totPendiente > 0 ? '#d97706' : '#16a34a' }};">
                        {{ number_format($totPendiente, 2, ',', '.') }}
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
