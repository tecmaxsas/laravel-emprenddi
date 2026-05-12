<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            📋 Actividad reciente
        </x-slot>
        <x-slot name="description">
            Últimas 20 transacciones (ventas + compras)
        </x-slot>

        @if ($activities->isEmpty())
            <div style="padding:30px; text-align:center; color:#9ca3af; font-size:13px;">
                Sin actividad registrada todavía.
            </div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5e7eb;">
                            <th style="text-align:left; padding:8px 10px; font-weight:700; color:#374151;">Fecha</th>
                            <th style="text-align:left; padding:8px 10px; font-weight:700; color:#374151;">Tipo</th>
                            <th style="text-align:left; padding:8px 10px; font-weight:700; color:#374151;">Documento</th>
                            <th style="text-align:right; padding:8px 10px; font-weight:700; color:#374151;">Monto</th>
                            <th style="text-align:center; padding:8px 10px; font-weight:700; color:#374151;">Pago</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activities as $a)
                            @php
                                $isSale = $a->kind === 'sale';
                                $kindLabel = $isSale ? '💰 Venta' : '📦 Compra';
                                $kindColor = $isSale ? '#059669' : '#dc2626';
                                $payStatus = $a->payment_status ?? 'pendiente';
                                $payColors = [
                                    'pagado' => ['bg' => '#dcfce7', 'fg' => '#166534'],
                                    'parcial' => ['bg' => '#fef3c7', 'fg' => '#92400e'],
                                    'pendiente' => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
                                ];
                                $pc = $payColors[$payStatus] ?? ['bg' => '#e5e7eb', 'fg' => '#374151'];
                                $editUrl = $isSale
                                    ? '/app/sale-invoices/'.$a->id
                                    : '/app/purchase-invoices/'.$a->id;
                            @endphp
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:8px 10px; color:#6b7280; font-family:monospace; white-space:nowrap;">
                                    {{ \Illuminate\Support\Carbon::parse($a->date)->format('d/m/Y') }}
                                </td>
                                <td style="padding:8px 10px; color:{{ $kindColor }}; font-weight:600;">
                                    {{ $kindLabel }}
                                </td>
                                <td style="padding:8px 10px; font-family:monospace;">
                                    <a href="{{ $editUrl }}" style="color:#3b82f6; text-decoration:none;">
                                        {{ $a->prefix }}-{{ str_pad((string) $a->number, 6, '0', STR_PAD_LEFT) }}
                                    </a>
                                </td>
                                <td style="padding:8px 10px; text-align:right; font-weight:700;">
                                    ${{ number_format((float) $a->total, 0, ',', '.') }}
                                </td>
                                <td style="padding:8px 10px; text-align:center;">
                                    <span style="background:{{ $pc['bg'] }}; color:{{ $pc['fg'] }}; font-size:10px; font-weight:700; padding:2px 8px; border-radius:999px;">
                                        {{ ucfirst($payStatus) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
