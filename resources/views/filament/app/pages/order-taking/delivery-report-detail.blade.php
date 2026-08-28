{{-- Detalle linea por linea de un pedido: que se despacho y que falta. --}}
<div style="overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; font-size:12.5px;">
        <thead>
            <tr style="background:#f1f5f9;">
                <th style="text-align:left;  padding:8px 10px; border-bottom:1px solid #cbd5e1;">Producto</th>
                <th style="text-align:right; padding:8px 10px; border-bottom:1px solid #cbd5e1;">Pedido</th>
                <th style="text-align:right; padding:8px 10px; border-bottom:1px solid #cbd5e1;">Despachado</th>
                <th style="text-align:right; padding:8px 10px; border-bottom:1px solid #cbd5e1;">Pendiente</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lineas as $linea)
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td style="padding:7px 10px;">{{ $linea['descripcion'] }}</td>
                    <td style="padding:7px 10px; text-align:right;">{{ number_format($linea['pedido'], 2, ',', '.') }}</td>
                    <td style="padding:7px 10px; text-align:right; color:#15803d;">{{ number_format($linea['despachado'], 2, ',', '.') }}</td>
                    <td style="padding:7px 10px; text-align:right; font-weight:700; color:{{ $linea['pendiente'] > 0 ? '#b45309' : '#15803d' }};">
                        {{ number_format($linea['pendiente'], 2, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="padding:14px 10px; text-align:center; color:#64748b;">
                        Este pedido no tiene líneas.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
