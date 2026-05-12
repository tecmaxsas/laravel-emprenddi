<x-filament-panels::page>
    @php
        $orders = $this->deliveryOrders;
        $drivers = $this->drivers;

        // Agrupar por estado para vista kanban-like
        $byStatus = [
            \App\Models\Restaurant\Order::DELIVERY_PREPARING => collect(),
            \App\Models\Restaurant\Order::DELIVERY_READY => collect(),
            \App\Models\Restaurant\Order::DELIVERY_ON_THE_WAY => collect(),
            \App\Models\Restaurant\Order::DELIVERY_DELIVERED => collect(),
        ];
        foreach ($orders as $o) {
            $st = $o->delivery_metadata['delivery_status'] ?? \App\Models\Restaurant\Order::DELIVERY_PREPARING;
            if (isset($byStatus[$st])) {
                $byStatus[$st]->push($o);
            }
        }
    @endphp

    {{-- Header --}}
    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; padding:14px 16px; background:#f3f4f6; border-radius:12px; border:1px solid #e5e7eb; margin-bottom:16px;"
         class="dark:!bg-gray-900 dark:!border-gray-800">
        <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:#374151;" class="dark:!text-gray-300">
            <input type="checkbox" wire:model.live="autoRefresh" style="width:16px; height:16px;" />
            Auto-refrescar (10s)
        </label>
        <div style="margin-left:auto; display:flex; gap:14px; font-size:12px; color:#6b7280;" class="dark:!text-gray-400">
            <span><strong style="color:#92400e;">{{ $byStatus[\App\Models\Restaurant\Order::DELIVERY_PREPARING]->count() }}</strong> preparando</span>
            <span><strong style="color:#1e40af;">{{ $byStatus[\App\Models\Restaurant\Order::DELIVERY_READY]->count() }}</strong> listo</span>
            <span><strong style="color:#3730a3;">{{ $byStatus[\App\Models\Restaurant\Order::DELIVERY_ON_THE_WAY]->count() }}</strong> en camino</span>
            <span><strong style="color:#166534;">{{ $byStatus[\App\Models\Restaurant\Order::DELIVERY_DELIVERED]->count() }}</strong> entregados</span>
        </div>
    </div>

    {{-- Empty state --}}
    @if ($orders->isEmpty())
        <div style="padding:60px 20px; text-align:center; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; color:#6b7280;">
            <div style="font-size:48px; margin-bottom:12px;">🛵</div>
            <div style="font-size:16px; font-weight:600; color:#111827;">Sin pedidos a domicilio activos</div>
            <div style="font-size:13px; margin-top:4px;">Cuando abras un nuevo domicilio desde el POS, aparecerá acá.</div>
        </div>
    @else
        {{-- Columnas tipo kanban --}}
        <div @if($autoRefresh) wire:poll.10s @endif
             style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px;">

            @foreach ([
                \App\Models\Restaurant\Order::DELIVERY_PREPARING => ['title' => '🍳 Preparando', 'next' => \App\Models\Restaurant\Order::DELIVERY_READY, 'nextLabel' => 'Marcar listo'],
                \App\Models\Restaurant\Order::DELIVERY_READY => ['title' => '📦 Listo para despachar', 'next' => \App\Models\Restaurant\Order::DELIVERY_ON_THE_WAY, 'nextLabel' => 'Salió 🛵'],
                \App\Models\Restaurant\Order::DELIVERY_ON_THE_WAY => ['title' => '🛵 En camino', 'next' => \App\Models\Restaurant\Order::DELIVERY_DELIVERED, 'nextLabel' => '✓ Entregado'],
                \App\Models\Restaurant\Order::DELIVERY_DELIVERED => ['title' => '✓ Entregado', 'next' => null, 'nextLabel' => null],
            ] as $status => $col)
                @php $colorMap = \App\Models\Restaurant\Order::DELIVERY_STATUS_COLORS[$status] ?? ['bg' => '#f3f4f6', 'fg' => '#374151']; @endphp
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:14px; padding:14px; display:flex; flex-direction:column; gap:10px; min-height:200px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:8px; border-bottom:2px solid {{ $colorMap['fg'] }};">
                        <h3 style="margin:0; font-size:14px; font-weight:700; color:{{ $colorMap['fg'] }};">{{ $col['title'] }}</h3>
                        <span style="background:{{ $colorMap['bg'] }}; color:{{ $colorMap['fg'] }}; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px;">
                            {{ $byStatus[$status]->count() }}
                        </span>
                    </div>

                    @forelse ($byStatus[$status] as $o)
                        @php
                            $meta = $o->delivery_metadata ?? [];
                            $custName = $meta['customer_name'] ?? '—';
                            $custPhone = $meta['customer_phone'] ?? null;
                            $address = $meta['address'] ?? '—';
                            $addrNotes = $meta['address_notes'] ?? null;
                            $driverId = $meta['driver_id'] ?? null;
                            $driverName = $meta['driver_name'] ?? null;
                            $itemsCount = $o->items->reject(fn ($i) => $i->kitchen_status === 'cancelled')->count();
                        @endphp
                        <div style="background:#ffffff; border-radius:10px; padding:12px; border-left:4px solid {{ $colorMap['fg'] }}; display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                                <div style="font-size:14px; font-weight:700; color:#111827;">{{ $custName }}</div>
                                <div style="font-size:10px; color:#6b7280; font-family:monospace;">{{ $o->fullNumber() }}</div>
                            </div>

                            @if ($custPhone)
                                <div style="font-size:12px; color:#374151;">📞 {{ $custPhone }}</div>
                            @endif

                            <div style="font-size:12px; color:#374151; line-height:1.4;">
                                📍 {{ $address }}
                                @if ($addrNotes)
                                    <div style="font-size:11px; color:#6b7280; margin-top:2px; font-style:italic;">{{ $addrNotes }}</div>
                                @endif
                            </div>

                            <div style="font-size:11px; color:#6b7280; display:flex; gap:8px;">
                                <span>{{ $itemsCount }} item(s)</span>
                                <span>·</span>
                                <span>${{ number_format((float) $o->total, 0, ',', '.') }}</span>
                                @if ((float) $o->delivery_fee > 0)
                                    <span>·</span>
                                    <span title="Domicilio">🛵 ${{ number_format((float) $o->delivery_fee, 0, ',', '.') }}</span>
                                @endif
                            </div>

                            {{-- Driver selector --}}
                            <div>
                                <label style="font-size:10px; color:#6b7280; font-weight:700; text-transform:uppercase; display:block; margin-bottom:2px;">Repartidor</label>
                                <select wire:change="assignDriver({{ $o->id }}, $event.target.value || null)"
                                        style="width:100%; padding:6px 8px; border-radius:6px; border:1px solid #d1d5db; font-size:12px; background:#ffffff; color:#111827;">
                                    <option value="">— Sin asignar —</option>
                                    @foreach ($drivers as $d)
                                        <option value="{{ $d->id }}" @selected((int) $driverId === (int) $d->id)>
                                            {{ $d->name }}@if($d->license_plate) ({{ $d->license_plate }})@endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Acciones de estado --}}
                            <div style="display:flex; gap:4px; margin-top:4px;">
                                @if ($col['next'])
                                    <button type="button" wire:click="setStatus({{ $o->id }}, '{{ $col['next'] }}')"
                                            style="flex:1; padding:7px 8px; background:{{ $colorMap['fg'] }}; color:white; border:0; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer;">
                                        {{ $col['nextLabel'] }} →
                                    </button>
                                @endif
                                @if ($status !== \App\Models\Restaurant\Order::DELIVERY_PREPARING)
                                    @php
                                        $prevMap = [
                                            \App\Models\Restaurant\Order::DELIVERY_READY => \App\Models\Restaurant\Order::DELIVERY_PREPARING,
                                            \App\Models\Restaurant\Order::DELIVERY_ON_THE_WAY => \App\Models\Restaurant\Order::DELIVERY_READY,
                                            \App\Models\Restaurant\Order::DELIVERY_DELIVERED => \App\Models\Restaurant\Order::DELIVERY_ON_THE_WAY,
                                        ];
                                    @endphp
                                    <button type="button" wire:click="setStatus({{ $o->id }}, '{{ $prevMap[$status] }}')"
                                            title="Volver al estado anterior"
                                            style="padding:7px 10px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:6px; font-size:12px; cursor:pointer;">↶</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div style="padding:24px 12px; text-align:center; color:#9ca3af; font-size:12px; font-style:italic;">
                            Sin pedidos
                        </div>
                    @endforelse
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
