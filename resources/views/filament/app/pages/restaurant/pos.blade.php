<x-filament-panels::page>
    @php
        $zones = $this->zones;
        $tables = $this->tables;
        $order = $this->activeOrder;
        $catalog = $this->catalog;
        $categories = $this->categories;
    @endphp

    <style>
        .rpos-grid { display:grid; grid-template-columns: 1fr; gap:16px; }
        @media (min-width: 1024px) {
            .rpos-grid-split { display:grid; grid-template-columns: 1fr 420px; gap:16px; }
        }
        .rpos-card { background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
        :is(.dark) .rpos-card { background:rgb(17,24,39); border-color:rgb(31,41,55); }
        .rpos-zone-pill { padding:6px 14px; border-radius:999px; font-size:13px; font-weight:600; cursor:pointer; border:2px solid transparent; transition:all 150ms; }
        .rpos-zone-pill.active { background:rgb(99,102,241); color:white; }
        .rpos-zone-pill.inactive { background:#f3f4f6; color:#374151; }
        :is(.dark) .rpos-zone-pill.inactive { background:rgb(31,41,55); color:rgb(229,231,235); }
        .rpos-table { padding:18px; border-radius:12px; border:3px solid; cursor:pointer; transition:transform 120ms; color:white; font-weight:700; text-align:center; box-shadow:0 4px 6px rgba(0,0,0,0.1); text-shadow:0 1px 2px rgba(0,0,0,0.3); }
        .rpos-table:hover { transform:translateY(-3px); box-shadow:0 8px 16px rgba(0,0,0,0.2); }
        .rpos-table-code { font-size:18px; line-height:1; }
        .rpos-table-meta { font-size:11px; opacity:0.95; margin-top:4px; }
        .rpos-catalog-btn { padding:10px 12px; border-radius:8px; background:#ffffff; border:1px solid #e5e7eb; cursor:pointer; text-align:left; font-size:13px; transition:all 120ms; }
        :is(.dark) .rpos-catalog-btn { background:rgb(31,41,55); border-color:rgb(55,65,81); color:rgb(229,231,235); }
        .rpos-catalog-btn:hover { background:rgb(238,242,255); border-color:rgb(99,102,241); }
        :is(.dark) .rpos-catalog-btn:hover { background:rgb(30,27,75); }
        .rpos-item-row { display:flex; gap:8px; padding:8px 10px; border-radius:8px; align-items:center; }
        .rpos-item-row:hover { background:#f9fafb; }
        :is(.dark) .rpos-item-row:hover { background:rgb(31,41,55); }
        .rpos-qty-btn { width:24px; height:24px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #d1d5db; background:#f9fafb; cursor:pointer; font-weight:600; }
        :is(.dark) .rpos-qty-btn { background:rgb(31,41,55); border-color:rgb(75,85,99); color:rgb(229,231,235); }
        .rpos-status-badge { font-size:10px; padding:2px 6px; border-radius:999px; font-weight:600; text-transform:uppercase; }
    </style>

    <div class="{{ $order ? 'rpos-grid-split' : 'rpos-grid' }}">
        {{-- =================================================== --}}
        {{-- IZQUIERDA: MAPA / GRID DE MESAS                     --}}
        {{-- =================================================== --}}
        <div>
            {{-- Filtro de zonas --}}
            <div class="rpos-card" style="margin-bottom:14px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span style="font-size:13px; font-weight:600; color:#6b7280; margin-right:4px;" class="dark:!text-gray-400">Zona:</span>

                    <button type="button" wire:click="$set('activeZoneId', null)"
                            class="rpos-zone-pill {{ $this->activeZoneId === null ? 'active' : 'inactive' }}">
                        Todas
                    </button>

                    @foreach ($zones as $z)
                        <button type="button" wire:click="$set('activeZoneId', {{ $z->id }})"
                                class="rpos-zone-pill {{ $this->activeZoneId === $z->id ? 'active' : 'inactive' }}"
                                style="@if($this->activeZoneId === $z->id) background: {{ $z->color }}; @endif">
                            {{ $z->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Grid de mesas (no es drag-drop, este es el POS, no la config) --}}
            @if ($tables->isEmpty())
                <div class="rpos-card" style="padding:60px 20px; text-align:center; color:#6b7280;">
                    <svg style="width:48px; height:48px; margin:0 auto 12px; opacity:0.4;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5"/></svg>
                    <div style="font-size:15px; font-weight:600;">No hay mesas activas en esta zona</div>
                    <div style="font-size:12px; margin-top:6px;">Crea mesas en <strong>Restaurante → Mesas</strong>.</div>
                </div>
            @else
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:12px;">
                    @foreach ($tables as $t)
                        @php
                            $bg = match ($t->status) {
                                'free' => '#10b981',
                                'occupied' => '#f59e0b',
                                'billing' => '#3b82f6',
                                'reserved' => '#a855f7',
                                'cleaning' => '#6b7280',
                                default => '#6b7280',
                            };
                            $zc = $t->zone?->color ?? '#9ca3af';
                            $isActive = $order && $order->table_id === $t->id;
                        @endphp
                        <button type="button"
                                wire:click="selectTable({{ $t->id }})"
                                class="rpos-table"
                                style="background:{{ $bg }}; border-color:{{ $isActive ? '#ffffff' : $zc }}; @if($isActive) outline:3px solid {{ $zc }}; outline-offset:2px; @endif">
                            <div class="rpos-table-code">{{ $t->code }}</div>
                            <div class="rpos-table-meta">{{ $t->capacity }} pers · {{ \App\Models\Restaurant\Table::STATUSES[$t->status] }}</div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- =================================================== --}}
        {{-- DERECHA: PANEL DE ORDEN ACTIVA (solo si hay orden)  --}}
        {{-- =================================================== --}}
        @if ($order)
            <div class="rpos-card" style="display:flex; flex-direction:column; gap:14px; max-height: calc(100vh - 160px); overflow-y:auto;">
                {{-- Header de la orden --}}
                <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:12px; border-bottom:1px solid #e5e7eb;" class="dark:!border-gray-800">
                    <div>
                        <div style="font-size:18px; font-weight:700;">{{ $order->table?->code ?? 'Delivery' }}</div>
                        <div style="font-size:11px; color:#6b7280;" class="dark:!text-gray-400">
                            {{ $order->fullNumber() }} · {{ $order->guests }} pers · {{ $order->opened_at->diffForHumans() }}
                        </div>
                    </div>
                    <button type="button" wire:click="closeOrderPanel"
                            title="Cerrar panel (la orden queda guardada)"
                            style="width:36px; height:36px; border-radius:8px; background:#ef4444; color:#ffffff; border:0; cursor:pointer; font-size:20px; font-weight:700; line-height:1; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 2px 4px rgba(0,0,0,0.15);">×</button>
                </div>

                {{-- Lista de items --}}
                <div style="display:flex; flex-direction:column; gap:6px;">
                    @if ($order->items->isEmpty())
                        <div style="padding:30px 12px; text-align:center; color:#9ca3af; font-size:13px;">
                            Sin items aún. Toca productos del catálogo abajo para agregarlos.
                        </div>
                    @else
                        @foreach ($order->items as $item)
                            @php
                                $statusColor = match ($item->kitchen_status) {
                                    'pending' => '#6b7280',
                                    'sent' => '#3b82f6',
                                    'preparing' => '#f59e0b',
                                    'ready' => '#10b981',
                                    'served' => '#059669',
                                    'cancelled' => '#dc2626',
                                    default => '#6b7280',
                                };
                            @endphp
                            <div class="rpos-item-row" style="border-left:3px solid {{ $statusColor }};">
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:13px; font-weight:600; @if($item->kitchen_status === 'cancelled') text-decoration:line-through; opacity:0.6; @endif">
                                        {{ $item->description }}
                                    </div>
                                    <div style="display:flex; gap:6px; align-items:center; margin-top:2px;">
                                        <span class="rpos-status-badge" style="background:{{ $statusColor }}33; color:{{ $statusColor }};">
                                            {{ \App\Models\Restaurant\OrderItem::KITCHEN_STATUSES[$item->kitchen_status] ?? $item->kitchen_status }}
                                        </span>
                                        @if ($item->item_note)
                                            <span style="font-size:11px; color:#6b7280; font-style:italic;">"{{ $item->item_note }}"</span>
                                        @endif
                                    </div>
                                </div>

                                @if (in_array($item->kitchen_status, ['pending', 'sent']))
                                    <button type="button" wire:click="decreaseQty({{ $item->id }})" class="rpos-qty-btn">−</button>
                                @endif

                                <span style="font-weight:700; min-width:30px; text-align:center;">{{ number_format((float) $item->quantity, 0) }}</span>

                                @if (in_array($item->kitchen_status, ['pending', 'sent']))
                                    <button type="button" wire:click="increaseQty({{ $item->id }})" class="rpos-qty-btn">+</button>
                                @endif

                                <span style="min-width:80px; text-align:right; font-weight:600; font-size:13px;">
                                    ${{ number_format((float) $item->total, 0, ',', '.') }}
                                </span>

                                @if ($item->kitchen_status === 'pending')
                                    <button type="button" wire:click="cancelItem({{ $item->id }})"
                                            class="rpos-qty-btn" style="color:#dc2626;" title="Eliminar">×</button>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>

                {{-- Totales --}}
                <div style="padding:12px; border-radius:8px; background:#f9fafb; display:flex; flex-direction:column; gap:4px; font-size:13px;"
                     class="dark:!bg-gray-800">
                    <div style="display:flex; justify-content:space-between;"><span>Subtotal</span><span>${{ number_format((float) $order->subtotal, 0, ',', '.') }}</span></div>
                    @if ((float) $order->tax_total > 0)
                        <div style="display:flex; justify-content:space-between;"><span>IVA</span><span>${{ number_format((float) $order->tax_total, 0, ',', '.') }}</span></div>
                    @endif
                    @if ((float) $order->tip_amount > 0)
                        <div style="display:flex; justify-content:space-between;"><span>Propina</span><span>${{ number_format((float) $order->tip_amount, 0, ',', '.') }}</span></div>
                    @endif
                    <div style="display:flex; justify-content:space-between; padding-top:6px; border-top:1px solid #e5e7eb; font-size:16px; font-weight:700;"
                         class="dark:!border-gray-700">
                        <span>TOTAL</span><span style="color:#10b981;">${{ number_format((float) $order->total, 0, ',', '.') }}</span>
                    </div>
                </div>

                {{-- Acciones --}}
                <div x-data="{ confirmCancel: false }">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                        <button type="button" wire:click="sendToKitchen"
                                @disabled($order->items->where('kitchen_status', 'pending')->isEmpty())
                                style="padding:10px; border-radius:8px; background:#3b82f6; color:white; border:0; font-weight:700; cursor:pointer; font-size:13px;"
                                wire:loading.attr="disabled"
                                wire:target="sendToKitchen">
                            <span wire:loading.remove wire:target="sendToKitchen">📨 Enviar a cocina</span>
                            <span wire:loading wire:target="sendToKitchen">Enviando...</span>
                        </button>
                        <button type="button" @click="confirmCancel = true"
                                style="padding:10px; border-radius:8px; background:#fee2e2; color:#991b1b; border:0; font-weight:600; cursor:pointer; font-size:13px;">
                            Cancelar orden
                        </button>
                    </div>

                    {{-- Modal de confirmación estilo Filament/SweetAlert --}}
                    <div x-show="confirmCancel"
                         x-cloak
                         x-transition.opacity
                         @click.self="confirmCancel = false"
                         @keydown.escape.window="confirmCancel = false"
                         style="position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; padding:20px; z-index:100;">
                        <div @click.stop
                             style="background:#ffffff; border-radius:14px; padding:24px; max-width:420px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); animation: rposModal 200ms ease-out;"
                             class="dark:!bg-gray-900">
                            <style>
                                @keyframes rposModal {
                                    from { opacity:0; transform: translateY(-10px) scale(0.95); }
                                    to { opacity:1; transform: translateY(0) scale(1); }
                                }
                            </style>
                            <div style="display:flex; gap:14px; align-items:flex-start;">
                                <div style="flex-shrink:0; width:48px; height:48px; border-radius:50%; background:#fee2e2; display:flex; align-items:center; justify-content:center;">
                                    <svg style="width:26px; height:26px; color:#dc2626;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                                    </svg>
                                </div>
                                <div style="flex:1;">
                                    <h3 style="font-size:18px; font-weight:700; margin:0; color:#111827;" class="dark:!text-gray-100">
                                        ¿Cancelar la orden?
                                    </h3>
                                    <p style="font-size:14px; color:#6b7280; margin:8px 0 0; line-height:1.5;" class="dark:!text-gray-400">
                                        Esta acción libera la mesa <strong>{{ $order->table?->code ?? '—' }}</strong> y marca la orden como anulada. No se podrá deshacer.
                                    </p>
                                </div>
                            </div>
                            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                                <button type="button" @click="confirmCancel = false"
                                        style="padding:10px 16px; border-radius:8px; background:#f3f4f6; color:#374151; border:0; font-weight:600; cursor:pointer; font-size:13px;"
                                        class="dark:!bg-gray-800 dark:!text-gray-200">
                                    Mantener orden
                                </button>
                                <button type="button" @click="confirmCancel = false; $wire.cancelOrder()"
                                        style="padding:10px 16px; border-radius:8px; background:#dc2626; color:white; border:0; font-weight:600; cursor:pointer; font-size:13px;">
                                    Sí, cancelar orden
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Catálogo de productos --}}
                <div style="border-top:2px dashed #e5e7eb; padding-top:14px;" class="dark:!border-gray-700">
                    <div style="display:flex; gap:6px; margin-bottom:8px;">
                        <input type="text" wire:model.live.debounce.300ms="productSearch"
                               placeholder="Buscar por nombre / código / barcode"
                               style="flex:1; padding:8px 10px; border-radius:8px; border:1px solid #d1d5db; font-size:13px;"
                               class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-gray-100" />
                    </div>

                    {{-- Tabs de categoría --}}
                    <div style="display:flex; gap:4px; flex-wrap:wrap; margin-bottom:10px;">
                        <button type="button" wire:click="$set('activeCategoryId', null)"
                                style="padding:4px 10px; font-size:11px; border-radius:6px; background:{{ $this->activeCategoryId === null ? 'rgb(99,102,241)' : '#f3f4f6' }}; color:{{ $this->activeCategoryId === null ? 'white' : '#374151' }}; border:0; cursor:pointer; font-weight:600;"
                                class="@if($this->activeCategoryId !== null) dark:!bg-gray-800 dark:!text-gray-300 @endif">
                            Todas
                        </button>
                        @foreach ($categories as $cat)
                            <button type="button" wire:click="$set('activeCategoryId', {{ $cat->id }})"
                                    style="padding:4px 10px; font-size:11px; border-radius:6px; background:{{ $this->activeCategoryId === $cat->id ? 'rgb(99,102,241)' : '#f3f4f6' }}; color:{{ $this->activeCategoryId === $cat->id ? 'white' : '#374151' }}; border:0; cursor:pointer; font-weight:600;"
                                    class="@if($this->activeCategoryId !== $cat->id) dark:!bg-gray-800 dark:!text-gray-300 @endif">
                                {{ $cat->name }}
                            </button>
                        @endforeach
                    </div>

                    {{-- Grid de productos --}}
                    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap:6px; max-height:300px; overflow-y:auto;">
                        @forelse ($catalog as $p)
                            <button type="button" wire:click="addProduct({{ $p->id }})" class="rpos-catalog-btn">
                                <div style="font-weight:600; font-size:12px; line-height:1.3;">{{ $p->name }}</div>
                                <div style="font-size:11px; color:#10b981; font-weight:700; margin-top:2px;">
                                    ${{ number_format((float) $p->default_sale_price, 0, ',', '.') }}
                                </div>
                            </button>
                        @empty
                            <div style="grid-column: 1/-1; padding:20px; text-align:center; color:#9ca3af; font-size:13px;">
                                Sin productos. Verifica que tengas productos con <code>is_sellable=true</code>.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
