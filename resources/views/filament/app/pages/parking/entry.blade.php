<x-filament-panels::page>
    {{ $this->form }}

    @if ($lastSession)
        <div style="margin-top:18px; border:1px solid #16a34a; background:#dcfce7; color:#166534; border-radius:10px; padding:14px 18px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; opacity:.7;">Última entrada registrada</div>
            <div style="font-size:18px; font-weight:800; margin-top:2px;">
                {{ $lastSession->plate }}
                @if ($lastSession->vehicleType)
                    · <span style="font-weight:500;">{{ $lastSession->vehicleType->name }}</span>
                @endif
            </div>
            <div style="font-size:13px; margin-top:4px;">
                {{ $lastSession->parkingLot?->name }} · Entrada
                <strong>{{ $lastSession->entry_at->format('d/m/Y H:i:s') }}</strong>
                · Ticket <code>#{{ str_pad($lastSession->id, 6, '0', STR_PAD_LEFT) }}</code>
            </div>
        </div>
    @endif
</x-filament-panels::page>
