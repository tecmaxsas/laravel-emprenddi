@php
    $fmt = fn ($n) => '$ ' . number_format((float) $n, 0, ',', '.');
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($activeSession && $quote)
        <div style="margin-top:18px; border:1px solid #6366f1; background:#eef2ff; color:#1e1b4b; border-radius:10px; padding:16px 20px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; opacity:.7;">Sesión activa</div>
                    <div style="font-size:24px; font-weight:900; margin-top:2px; font-family:ui-monospace, monospace; letter-spacing:1.5px;">{{ $activeSession->plate }}</div>
                    <div style="font-size:13px; margin-top:4px;">
                        {{ $activeSession->parkingLot?->name }}
                        @if ($activeSession->vehicleType) · {{ $activeSession->vehicleType->name }} @endif
                    </div>
                    <div style="font-size:13px; margin-top:2px;">
                        Entrada: <strong>{{ $activeSession->entry_at->format('d/m/Y H:i:s') }}</strong>
                        · Tiempo: <strong>{{ $quote['minutes'] ?? 0 }} min</strong>
                    </div>
                </div>

                <div style="text-align:right;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; opacity:.7;">Total a cobrar</div>
                    <div style="font-size:32px; font-weight:900; color:#16a34a;">
                        {{ $fmt($quote['amount'] ?? 0) }}
                    </div>
                </div>
            </div>

            @if (! empty($quote['breakdown']))
                <table style="width:100%; margin-top:14px; border-top:1px solid #c7d2fe; padding-top:10px; font-size:13px;">
                    @foreach ($quote['breakdown'] as $row)
                        <tr>
                            <td style="padding:4px 0;">{{ $row['label'] }}</td>
                            <td style="text-align:right; font-family:ui-monospace, monospace; padding:4px 0; font-weight:{{ $row['amount'] < 0 ? '700' : '500' }}; color:{{ $row['amount'] < 0 ? '#dc2626' : '#1e1b4b' }};">
                                {{ $fmt($row['amount']) }}
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if (! empty($quote['error']))
                <div style="margin-top:10px; padding:8px 12px; background:#fee2e2; color:#991b1b; border-radius:6px; font-size:13px;">
                    ⚠ {{ $quote['error'] }}
                </div>
            @endif

            <div style="display:flex; gap:10px; margin-top:14px; flex-wrap:wrap;">
                <button type="button" wire:click="processExit"
                    style="padding:12px 22px; background:#16a34a; color:#fff; border:0; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer;">
                    ✓ Registrar salida y cobrar
                </button>
                <button type="button" wire:click="processLostTicket"
                    onclick="return confirm('¿Confirmar cobro por ticket perdido?')"
                    style="padding:12px 22px; background:#f59e0b; color:#fff; border:0; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer;">
                    ⚠ Ticket perdido
                </button>
                <button type="button" wire:click="refreshQuote"
                    style="padding:12px 18px; background:#475569; color:#fff; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer;">
                    🔄 Actualizar
                </button>
            </div>

            <details style="margin-top:14px;">
                <summary style="cursor:pointer; font-size:12px; color:#6b7280; font-weight:600;">Anular sesión (error de registro)</summary>
                <div style="display:flex; gap:8px; margin-top:8px;">
                    <input type="text" wire:model="data.cancel_reason" placeholder="Motivo de anulación"
                        style="flex:1; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" />
                    <button type="button" wire:click="cancelSession"
                        onclick="return confirm('¿Anular esta sesión?')"
                        style="padding:8px 14px; background:#dc2626; color:#fff; border:0; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer;">
                        Anular
                    </button>
                </div>
            </details>
        </div>
    @endif

    @if ($lastClosedSession)
        <div style="margin-top:18px; border:1px solid #16a34a; background:#dcfce7; color:#166534; border-radius:10px; padding:14px 18px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; opacity:.7;">Última salida procesada</div>
            <div style="font-size:18px; font-weight:800; margin-top:2px;">
                {{ $lastClosedSession->plate }} · {{ $fmt($lastClosedSession->amount) }}
            </div>
            <div style="font-size:12.5px; margin-top:4px;">
                {{ $lastClosedSession->parkingLot?->name }}
                · {{ $lastClosedSession->statusLabel() }}
                · {{ $lastClosedSession->total_minutes ?? '—' }} min
                · Ticket <code>#{{ str_pad($lastClosedSession->id, 6, '0', STR_PAD_LEFT) }}</code>
            </div>
        </div>
    @endif
</x-filament-panels::page>
