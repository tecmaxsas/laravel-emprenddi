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
            {{-- Filtro de zonas + boton para llevar --}}
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

                    <button type="button" wire:click="openTakeawayPrompt"
                            style="margin-left:auto; padding:8px 16px; border-radius:8px; background:#ea580c; color:white; border:0; font-weight:700; cursor:pointer; font-size:13px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        🥡 Nueva para llevar
                    </button>
                </div>
            </div>

            {{-- Próximas reservas --}}
            @php $upcomingRes = $this->upcomingReservations; @endphp
            @if ($upcomingRes->isNotEmpty())
                <div class="rpos-card" style="margin-bottom:14px; background:#eef2ff; border-color:#c7d2fe;">
                    <div style="font-size:11px; color:#3730a3; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                        📅 Próximas reservas ({{ $upcomingRes->count() }})
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        @foreach ($upcomingRes as $res)
                            @php
                                $diff = $res->reserved_for->diffInMinutes(now(), false);
                                $isPast = $res->reserved_for->isPast();
                                $isImminent = abs($diff) <= 15;
                                $borderColor = $isPast ? '#dc2626' : ($isImminent ? '#f59e0b' : '#a5b4fc');
                                $bg = $isPast ? '#fef2f2' : ($isImminent ? '#fffbeb' : '#ffffff');
                            @endphp
                            <div style="padding:10px 12px; border-radius:8px; background:{{ $bg }}; border:2px solid {{ $borderColor }}; min-width:220px;">
                                <div style="display:flex; justify-content:space-between; align-items:baseline; gap:8px;">
                                    <div style="font-weight:700; font-size:13px; color:#111827;">
                                        {{ $res->customer_name }}
                                    </div>
                                    <div style="font-size:11px; color:{{ $isPast ? '#dc2626' : '#374151' }}; font-weight:600;">
                                        {{ $res->reserved_for->format('H:i') }}
                                        @if ($isPast)
                                            <span style="font-weight:700;">· hace {{ abs($diff) }}min</span>
                                        @else
                                            <span>· en {{ $diff === 0 ? 'ahora' : abs($diff).'min' }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div style="font-size:11px; color:#6b7280; margin-top:2px;">
                                    {{ $res->guests }} pers ·
                                    @if ($res->table)
                                        Mesa {{ $res->table->code }}
                                    @elseif ($res->zone)
                                        Zona {{ $res->zone->name }}
                                    @else
                                        Sin mesa asignada
                                    @endif
                                    @if ($res->customer_phone) · {{ $res->customer_phone }} @endif
                                </div>
                                @if ($res->notes)
                                    <div style="font-size:10px; color:#92400e; background:#fef3c7; padding:3px 6px; border-radius:4px; margin-top:4px; line-height:1.3;">
                                        ⚠ {{ \Illuminate\Support\Str::limit($res->notes, 80) }}
                                    </div>
                                @endif
                                <div style="display:flex; gap:4px; margin-top:6px;">
                                    <button type="button" wire:click="seatReservation({{ $res->id }})"
                                            style="flex:1; padding:5px 8px; border-radius:6px; background:#059669; color:white; border:0; font-weight:700; cursor:pointer; font-size:11px;">
                                        ✓ Sentar
                                    </button>
                                    <button type="button" wire:click="markReservationNoShow({{ $res->id }})"
                                            title="Marcar como no vino"
                                            style="padding:5px 8px; border-radius:6px; background:transparent; color:#dc2626; border:1px solid #fecaca; font-weight:600; cursor:pointer; font-size:11px;">
                                        No vino
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Ordenes "para llevar" activas (sin mesa) --}}
            @php $takeawayList = $this->takeawayOrders; @endphp
            @if ($takeawayList->isNotEmpty())
                <div class="rpos-card" style="margin-bottom:14px; background:#fff7ed; border-color:#fed7aa;">
                    <div style="font-size:11px; color:#9a3412; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                        🥡 Para llevar / pickup activos ({{ $takeawayList->count() }})
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        @foreach ($takeawayList as $to)
                            @php
                                $custName = $to->delivery_metadata['customer_name'] ?? null;
                                $isActiveT = $order && $order->id === $to->id;
                                $itemsCount = $to->items->reject(fn ($i) => $i->kitchen_status === 'cancelled')->count();
                            @endphp
                            <button type="button" wire:click="$set('activeOrderId', {{ $to->id }})"
                                    style="padding:8px 12px; border-radius:8px; background:{{ $isActiveT ? '#ea580c' : '#ffffff' }}; color:{{ $isActiveT ? '#ffffff' : '#111827' }}; border:2px solid {{ $isActiveT ? '#ea580c' : '#fed7aa' }}; cursor:pointer; font-size:12px; text-align:left; min-width:130px;">
                                <div style="font-weight:700; font-size:13px;">
                                    {{ $custName ?: $to->fullNumber() }}
                                </div>
                                <div style="font-size:10px; opacity:0.85; margin-top:2px;">
                                    {{ $itemsCount }} item(s) · ${{ number_format((float) $to->total, 0, ',', '.') }}
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

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
                        @php
                            $headerTitle = $order->table?->code
                                ?? ($order->delivery_metadata['customer_name'] ?? null)
                                ?? ($order->is_takeaway ? '🥡 Para llevar' : 'Delivery');
                        @endphp
                        <div style="font-size:18px; font-weight:700;">{{ $headerTitle }}</div>
                        <div style="font-size:11px; color:#6b7280;" class="dark:!text-gray-400">
                            {{ $order->fullNumber() }} · {{ $order->guests }} pers · {{ $order->opened_at->diffForHumans() }}
                        </div>
                    </div>
                    <button type="button" wire:click="closeOrderPanel"
                            title="Cerrar panel (la orden queda guardada)"
                            style="width:36px; height:36px; border-radius:8px; background:#ef4444; color:#ffffff; border:0; cursor:pointer; font-size:20px; font-weight:700; line-height:1; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 2px 4px rgba(0,0,0,0.15);">×</button>
                </div>

                {{-- Modo de servicio --}}
                <div style="display:flex; gap:6px; padding:6px; background:#f3f4f6; border-radius:8px;">
                    @php
                        $modeIsDineIn = ! $order->is_takeaway && ! $order->is_delivery;
                        $modeIsTakeaway = (bool) $order->is_takeaway;
                    @endphp
                    <button type="button" wire:click="setServiceMode('dine_in')"
                            @disabled(! $order->table_id)
                            title="{{ $order->table_id ? 'Comer aquí' : 'No disponible: la orden no tiene mesa asignada' }}"
                            style="flex:1; padding:8px; border-radius:6px; background:{{ $modeIsDineIn ? '#10b981' : 'transparent' }}; color:{{ $modeIsDineIn ? '#ffffff' : '#374151' }}; border:0; font-weight:700; cursor:{{ $order->table_id ? 'pointer' : 'not-allowed' }}; font-size:12px; opacity:{{ $order->table_id ? '1' : '0.5' }};">
                        🍽️ Comer aquí
                    </button>
                    <button type="button" wire:click="setServiceMode('takeaway')"
                            style="flex:1; padding:8px; border-radius:6px; background:{{ $modeIsTakeaway ? '#ea580c' : 'transparent' }}; color:{{ $modeIsTakeaway ? '#ffffff' : '#374151' }}; border:0; font-weight:700; cursor:pointer; font-size:12px;">
                        🥡 Para llevar
                    </button>
                </div>

                {{-- Ops de mesa: transferir / juntar --}}
                @if ($order->table_id)
                    <div style="display:flex; gap:6px;">
                        <button type="button" wire:click="openTransferModal"
                                title="Mover esta orden a otra mesa libre"
                                style="flex:1; padding:8px; border-radius:8px; background:#f3e8ff; color:#6b21a8; border:1px solid #d8b4fe; font-weight:600; cursor:pointer; font-size:12px;">
                            🔄 Transferir mesa
                        </button>
                        <button type="button" wire:click="openMergeModal"
                                title="Fusionar con otra orden abierta"
                                style="flex:1; padding:8px; border-radius:8px; background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; font-weight:600; cursor:pointer; font-size:12px;">
                            🔗 Juntar mesas
                        </button>
                    </div>
                @endif

                {{-- Selector de curso "actual" — items nuevos heredan este --}}
                @php
                    $courses = \App\Models\Restaurant\OrderItem::COURSES;
                    $courseIcons = \App\Models\Restaurant\OrderItem::COURSE_ICONS;
                @endphp
                <div style="background:#ffffff; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px;">
                    <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                        Curso al agregar
                    </div>
                    <div style="display:flex; gap:4px;">
                        @foreach ($courses as $cNum => $cName)
                            @php $isActive = (int) $currentCourse === (int) $cNum; @endphp
                            <button type="button" wire:click="setCurrentCourse({{ $cNum }})"
                                    title="{{ $cName }}"
                                    style="flex:1; padding:6px 4px; border-radius:6px; border:1px solid {{ $isActive ? '#3b82f6' : '#d1d5db' }}; background:{{ $isActive ? '#3b82f6' : '#ffffff' }}; color:{{ $isActive ? '#ffffff' : '#374151' }}; cursor:pointer; font-size:11px; font-weight:{{ $isActive ? '700' : '500' }}; line-height:1.2; transition:all 100ms;">
                                <div style="font-size:14px;">{{ $courseIcons[$cNum] ?? '' }}</div>
                                <div>{{ $cName }}</div>
                            </button>
                        @endforeach
                    </div>
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
                                    <div style="display:flex; gap:6px; align-items:center; margin-top:2px; flex-wrap:wrap;">
                                        <span class="rpos-status-badge" style="background:{{ $statusColor }}33; color:{{ $statusColor }};">
                                            {{ \App\Models\Restaurant\OrderItem::KITCHEN_STATUSES[$item->kitchen_status] ?? $item->kitchen_status }}
                                        </span>
                                        @php
                                            $itemCourse = (int) $item->course;
                                            $courseName = $courses[$itemCourse] ?? ('C'.$itemCourse);
                                            $courseIcon = $courseIcons[$itemCourse] ?? '•';
                                            $isPendingItem = $item->kitchen_status === 'pending';
                                        @endphp
                                        @if ($isPendingItem)
                                            <button type="button" wire:click="cycleItemCourse({{ $item->id }})"
                                                    title="Click para cambiar curso"
                                                    style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; border-radius:999px; font-size:10px; font-weight:700; padding:2px 8px; cursor:pointer;">
                                                {{ $courseIcon }} {{ $courseName }} ⇄
                                            </button>
                                        @else
                                            <span style="background:#f3f4f6; color:#4b5563; border-radius:999px; font-size:10px; font-weight:700; padding:2px 8px;">
                                                {{ $courseIcon }} {{ $courseName }}
                                            </span>
                                        @endif
                                        @if ($item->item_note)
                                            <span style="font-size:11px; color:#6b7280; font-style:italic;">"{{ $item->item_note }}"</span>
                                        @endif
                                        {{-- Tag de tab (solo en split by_item) --}}
                                        @if ($splitMode === 'by_item')
                                            @if ($item->split_tab)
                                                <span style="background:#7c3aed; color:white; border-radius:999px; font-size:10px; font-weight:700; padding:2px 8px; display:inline-flex; align-items:center; gap:4px;">
                                                    Tab {{ $item->split_tab }}
                                                    <button type="button" wire:click="unassignItemTab({{ $item->id }})"
                                                            title="Quitar etiqueta"
                                                            style="background:transparent; color:white; border:0; cursor:pointer; padding:0; font-size:12px; line-height:1;">×</button>
                                                </span>
                                            @else
                                                <span style="display:inline-flex; gap:2px; align-items:center;">
                                                    @foreach (['A', 'B', 'C', 'D'] as $tabKey)
                                                        <button type="button" wire:click="assignItemTab({{ $item->id }}, '{{ $tabKey }}')"
                                                                title="Asignar tab {{ $tabKey }}"
                                                                style="background:#ede9fe; color:#5b21b6; border:1px dashed #c4b5fd; border-radius:4px; font-size:10px; font-weight:700; padding:1px 6px; cursor:pointer;">
                                                            {{ $tabKey }}
                                                        </button>
                                                    @endforeach
                                                </span>
                                            @endif
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
                <div style="padding:12px; border-radius:8px; background:#ffffff; border:1px solid #d1d5db; display:flex; flex-direction:column; gap:6px; font-size:13px; color:#111827;">
                    <div style="display:flex; justify-content:space-between; color:#374151;">
                        <span>Subtotal</span>
                        <span style="font-weight:600;">${{ number_format((float) $order->subtotal, 0, ',', '.') }}</span>
                    </div>
                    @if ((float) $order->tax_total > 0)
                        <div style="display:flex; justify-content:space-between; color:#374151;">
                            <span>IVA</span>
                            <span style="font-weight:600;">${{ number_format((float) $order->tax_total, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    @if ((float) $order->tip_amount > 0)
                        <div style="display:flex; justify-content:space-between; color:#374151;">
                            <span>Propina</span>
                            <span style="font-weight:600;">${{ number_format((float) $order->tip_amount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div style="display:flex; justify-content:space-between; padding-top:8px; margin-top:2px; border-top:1px solid #d1d5db; font-size:17px; font-weight:800; color:#111827;">
                        <span>TOTAL</span>
                        <span style="color:#059669;">${{ number_format((float) $order->total, 0, ',', '.') }}</span>
                    </div>
                </div>

                {{-- Propina --}}
                <div style="background:#fefce8; border:1px solid #fde047; border-radius:8px; padding:10px;">
                    <div style="font-size:11px; font-weight:700; color:#854d0e; text-transform:uppercase; margin-bottom:6px; letter-spacing:0.5px;">
                        💵 Propina
                    </div>
                    <div style="display:flex; gap:4px; margin-bottom:6px;">
                        @foreach ([0, 5, 10, 15] as $pct)
                            @php $active = (float) $order->tip_percentage === (float) $pct; @endphp
                            <button type="button" wire:click="applyTipPercent({{ $pct }})"
                                    style="flex:1; padding:6px 4px; border-radius:6px; border:1px solid {{ $active ? '#f59e0b' : '#d1d5db' }}; background:{{ $active ? '#f59e0b' : '#ffffff' }}; color:{{ $active ? '#ffffff' : '#374151' }}; cursor:pointer; font-size:12px; font-weight:700;">
                                {{ $pct }}%
                            </button>
                        @endforeach
                    </div>
                    <div style="display:flex; gap:4px;">
                        <input type="text" wire:model="customTipAmount"
                               wire:keydown.enter="applyCustomTip"
                               placeholder="O monto fijo $"
                               style="flex:1; padding:6px 8px; border-radius:6px; border:1px solid #d1d5db; font-size:12px; color:#111827; background:#ffffff;" />
                        <button type="button" wire:click="applyCustomTip"
                                style="padding:6px 10px; border-radius:6px; background:#f59e0b; color:white; border:0; font-weight:700; cursor:pointer; font-size:12px;">
                            ✓
                        </button>
                    </div>
                </div>

                {{-- División de cuenta --}}
                <div style="background:#f5f3ff; border:1px solid #c4b5fd; border-radius:8px; padding:10px;">
                    <div style="font-size:11px; font-weight:700; color:#5b21b6; text-transform:uppercase; margin-bottom:6px; letter-spacing:0.5px;">
                        🪙 División de cuenta
                    </div>
                    <div style="display:flex; gap:4px;">
                        <button type="button" wire:click="setSplitMode('none')"
                                style="flex:1; padding:6px; border-radius:6px; border:1px solid {{ $splitMode === 'none' ? '#7c3aed' : '#d1d5db' }}; background:{{ $splitMode === 'none' ? '#7c3aed' : '#ffffff' }}; color:{{ $splitMode === 'none' ? '#ffffff' : '#374151' }}; cursor:pointer; font-size:11px; font-weight:700;">
                            Una cuenta
                        </button>
                        <button type="button" wire:click="setSplitMode('by_item')"
                                style="flex:1; padding:6px; border-radius:6px; border:1px solid {{ $splitMode === 'by_item' ? '#7c3aed' : '#d1d5db' }}; background:{{ $splitMode === 'by_item' ? '#7c3aed' : '#ffffff' }}; color:{{ $splitMode === 'by_item' ? '#ffffff' : '#374151' }}; cursor:pointer; font-size:11px; font-weight:700;">
                            Dividir por item
                        </button>
                    </div>
                    @if ($splitMode === 'by_item')
                        <div style="font-size:10px; color:#5b21b6; margin-top:6px; line-height:1.4;">
                            Asigna una etiqueta (A, B, C…) a cada item. Cada etiqueta es una factura aparte.
                        </div>
                    @endif
                </div>

                {{-- Acciones --}}
                <div x-data="{ confirmCancel: false, confirmClose: false }">
                    @php
                        // Cuenta items pendientes agrupados por curso
                        $pendingByCourse = $order->items
                            ->where('kitchen_status', 'pending')
                            ->groupBy('course')
                            ->map->count();
                        $totalPending = $pendingByCourse->sum();
                    @endphp

                    <div style="display:grid; grid-template-columns: 1fr; gap:8px;">
                        {{-- Botón "Enviar TODO" siempre primero, más fácil de tocar --}}
                        <button type="button" wire:click="sendToKitchen"
                                @disabled($totalPending === 0)
                                style="padding:12px; border-radius:8px; background:{{ $totalPending > 0 ? '#3b82f6' : '#9ca3af' }}; color:white; border:0; font-weight:700; cursor:pointer; font-size:14px;"
                                wire:loading.attr="disabled"
                                wire:target="sendToKitchen">
                            <span wire:loading.remove wire:target="sendToKitchen">📨 Enviar TODO a cocina ({{ $totalPending }})</span>
                            <span wire:loading wire:target="sendToKitchen">Enviando + imprimiendo...</span>
                        </button>

                        {{-- Botones por curso (solo si hay >1 curso pendiente) --}}
                        @if ($pendingByCourse->count() > 1)
                            <div style="font-size:10px; color:#6b7280; text-transform:uppercase; font-weight:700; margin-top:4px; letter-spacing:0.5px;">
                                O enviar curso por curso
                            </div>
                            <div style="display:grid; grid-template-columns: repeat({{ min($pendingByCourse->count(), 4) }}, 1fr); gap:6px;">
                                @foreach ($pendingByCourse as $cNum => $cCount)
                                    <button type="button" wire:click="sendToKitchen({{ $cNum }})"
                                            style="padding:8px 4px; border-radius:8px; background:#1e40af; color:white; border:0; font-weight:700; cursor:pointer; font-size:11px; line-height:1.3;"
                                            wire:loading.attr="disabled"
                                            wire:target="sendToKitchen({{ $cNum }})">
                                        <div style="font-size:14px;">{{ $courseIcons[$cNum] ?? '' }}</div>
                                        <div>{{ $courses[$cNum] ?? 'C'.$cNum }}</div>
                                        <div style="font-size:10px; opacity:0.85;">({{ $cCount }})</div>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <button type="button" wire:click="openBillingModal"
                                @disabled($order->items->reject(fn ($i) => $i->kitchen_status === 'cancelled')->isEmpty())
                                style="padding:14px; border-radius:8px; background:#10b981; color:white; border:0; font-weight:800; cursor:pointer; font-size:15px; margin-top:6px; box-shadow:0 2px 4px rgba(16,185,129,0.3);">
                            💵 Cobrar cuenta y facturar
                        </button>

                        <button type="button" @click="confirmClose = true"
                                @disabled($order->items->isEmpty())
                                title="Cerrar sin generar factura (casa invita)"
                                style="padding:8px; border-radius:8px; background:transparent; color:#10b981; border:1px solid #a7f3d0; font-weight:600; cursor:pointer; font-size:11px;">
                            Cerrar sin facturar (casa invita)
                        </button>

                        <button type="button" @click="confirmCancel = true"
                                style="padding:8px; border-radius:8px; background:transparent; color:#dc2626; border:1px solid #fecaca; font-weight:600; cursor:pointer; font-size:12px;">
                            Cancelar orden (sin cobrar)
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

                    {{-- Modal de confirmación de CIERRE (cobro) --}}
                    <div x-show="confirmClose"
                         x-cloak
                         x-transition.opacity
                         @click.self="confirmClose = false"
                         @keydown.escape.window="confirmClose = false"
                         style="position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; padding:20px; z-index:100;">
                        <div @click.stop
                             style="background:#ffffff; border-radius:14px; padding:24px; max-width:460px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); animation: rposModal 200ms ease-out;"
                             class="dark:!bg-gray-900">
                            <div style="display:flex; gap:14px; align-items:flex-start;">
                                <div style="flex-shrink:0; width:48px; height:48px; border-radius:50%; background:#d1fae5; display:flex; align-items:center; justify-content:center;">
                                    <svg style="width:26px; height:26px; color:#059669;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                    </svg>
                                </div>
                                <div style="flex:1;">
                                    <h3 style="font-size:18px; font-weight:700; margin:0; color:#111827;" class="dark:!text-gray-100">
                                        Cerrar cuenta de {{ $order->table?->code ?? 'orden' }}
                                    </h3>
                                    <p style="font-size:14px; color:#6b7280; margin:8px 0 0; line-height:1.5;" class="dark:!text-gray-400">
                                        Total <strong style="color:#10b981;">${{ number_format((float) $order->total, 0, ',', '.') }}</strong>.
                                        La mesa <strong>{{ $order->table?->code ?? '—' }}</strong> quedará libre para una nueva orden.
                                    </p>
                                    <div style="margin-top:8px; padding:8px 10px; background:#fef3c7; border-radius:6px; font-size:11px; color:#92400e;">
                                        ℹ️ Próximamente — Iter 21e: capturar propina, dividir cuenta y generar factura DIAN.
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                                <button type="button" @click="confirmClose = false"
                                        style="padding:10px 16px; border-radius:8px; background:#f3f4f6; color:#374151; border:0; font-weight:600; cursor:pointer; font-size:13px;"
                                        class="dark:!bg-gray-800 dark:!text-gray-200">
                                    Volver
                                </button>
                                <button type="button" @click="confirmClose = false; $wire.closeOrder()"
                                        style="padding:10px 16px; border-radius:8px; background:#10b981; color:white; border:0; font-weight:700; cursor:pointer; font-size:13px;">
                                    ✓ Cerrar cuenta
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

                        <button type="button" wire:click="openHalfModal"
                                title="Pizza/pasta con dos sabores"
                                style="padding:8px 14px; border-radius:8px; background:#a855f7; color:white; border:0; font-weight:700; cursor:pointer; font-size:13px; white-space:nowrap;">
                            🍕 1/2 + 1/2
                        </button>
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

    {{-- ============ MODAL DE MODIFICADORES ============ --}}
    @if ($this->modifierProduct)
        @php
            $mp = $this->modifierProduct;
            $mGroups = $mp->modifierGroups()->with(['modifiers' => fn ($q) => $q->where('active', true)])->get();
        @endphp
        <div
             style="position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999; padding:20px;"
             wire:click.self="cancelModifiers"
             wire:keydown.escape.window="cancelModifiers"
        >
            <div style="background:white; border-radius:14px; padding:0; max-width:600px; width:100%; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.4);"
                 class="dark:!bg-gray-900">

                {{-- Header --}}
                <div style="padding:18px 22px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;"
                     class="dark:!border-gray-700">
                    <div>
                        <h2 style="margin:0; font-size:18px; font-weight:700; color:#111827;" class="dark:!text-gray-100">
                            🍽️ {{ $mp->name }}
                        </h2>
                        <div style="font-size:12px; color:#6b7280; margin-top:2px;" class="dark:!text-gray-400">
                            Base: ${{ number_format((float) $mp->default_sale_price, 0) }} — elige las opciones
                        </div>
                    </div>
                    <button type="button" wire:click="cancelModifiers"
                            style="background:transparent; border:0; cursor:pointer; padding:6px; border-radius:6px; font-size:24px; color:#6b7280; line-height:1;">
                        ×
                    </button>
                </div>

                {{-- Cuerpo (scrolleable) --}}
                <div style="padding:18px 22px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:18px;">
                    @forelse ($mGroups as $group)
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px;">
                                <div>
                                    <span style="font-size:14px; font-weight:700; color:#111827;" class="dark:!text-gray-100">
                                        {{ $group->name }}
                                    </span>
                                    @if ($group->required)
                                        <span style="font-size:10px; color:#dc2626; font-weight:700; margin-left:6px;">*Obligatorio</span>
                                    @endif
                                </div>
                                <span style="font-size:11px; color:#6b7280;" class="dark:!text-gray-400">
                                    @if ($group->max_select <= 1)
                                        Elige 1
                                    @else
                                        Min {{ $group->min_select }} / Max {{ $group->max_select }}
                                    @endif
                                </span>
                            </div>

                            @if ($group->description)
                                <div style="font-size:12px; color:#6b7280; margin-bottom:8px;" class="dark:!text-gray-400">
                                    {{ $group->description }}
                                </div>
                            @endif

                            <div style="display:flex; flex-direction:column; gap:6px;">
                                @php $inputType = $group->max_select <= 1 ? 'radio' : 'checkbox'; @endphp
                                @foreach ($group->modifiers as $modifier)
                                    <label style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; cursor:pointer; font-size:14px; background:#ffffff; color:#111827;">
                                        <span style="display:flex; align-items:center; gap:10px; color:#111827;">
                                            <input type="{{ $inputType }}"
                                                   wire:model.live="modifierSelections.{{ $group->id }}"
                                                   value="{{ $modifier->id }}"
                                                   style="width:18px; height:18px; accent-color:#10b981;" />
                                            <span style="color:#111827; font-weight:500;">{{ $modifier->name }}</span>
                                        </span>
                                        @if ((float) $modifier->price_delta != 0)
                                            <span style="font-size:13px; font-weight:700; color:{{ (float) $modifier->price_delta > 0 ? '#dc2626' : '#10b981' }};">
                                                {{ (float) $modifier->price_delta > 0 ? '+' : '' }}${{ number_format((float) $modifier->price_delta, 0) }}
                                            </span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div style="color:#6b7280; font-size:13px; text-align:center; padding:20px;">
                            Este producto no tiene grupos activos.
                        </div>
                    @endforelse

                    {{-- Nota libre --}}
                    <div>
                        <label style="font-size:13px; font-weight:600; color:#111827; margin-bottom:6px; display:block;" class="dark:!text-gray-200">
                            📝 Nota para cocina (opcional)
                        </label>
                        <textarea wire:model.live="modifierItemNote"
                                  placeholder="Ej: bien cocido, sin sal, alergia a maní..."
                                  rows="2"
                                  style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; resize:vertical;"
                                  class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-gray-100"></textarea>
                    </div>
                </div>

                {{-- Footer --}}
                <div style="padding:14px 22px; border-top:1px solid #e5e7eb; display:flex; gap:10px; justify-content:flex-end;"
                     class="dark:!border-gray-700">
                    <button type="button" wire:click="cancelModifiers"
                            style="padding:10px 18px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-weight:600; cursor:pointer;"
                            class="dark:!border-gray-600 dark:!text-gray-300">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmModifiers"
                            style="padding:10px 22px; background:#10b981; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer;">
                        ✓ Agregar a la cuenta
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ MODAL MITAD Y MITAD ============ --}}
    @if ($halfModalOpen)
        @php
            $halfAOpts = $this->halfAOptions;
            $halfBOpts = $this->halfBOptions;
            $preview = $this->halfPreview;
        @endphp
        <div
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999; padding:20px;"
            wire:click.self="closeHalfModal"
            wire:keydown.escape.window="closeHalfModal"
        >
            <div style="background:#ffffff; border-radius:14px; max-width:560px; width:100%; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.4);">

                {{-- Header --}}
                <div style="padding:18px 22px; border-bottom:1px solid #e5e7eb; background:#faf5ff; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2 style="margin:0; font-size:18px; font-weight:700; color:#581c87;">
                            🍕 Mitad y mitad
                        </h2>
                        <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                            Combina 2 productos de la misma categoría en una sola línea
                        </div>
                    </div>
                    <button type="button" wire:click="closeHalfModal"
                            style="background:transparent; border:0; cursor:pointer; padding:6px; border-radius:6px; font-size:24px; color:#6b7280; line-height:1;">
                        ×
                    </button>
                </div>

                {{-- Cuerpo --}}
                <div style="padding:18px 22px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:14px; color:#111827;">

                    {{-- Mitad A --}}
                    <div>
                        <label style="display:block; font-size:13px; font-weight:700; color:#111827; margin-bottom:6px;">
                            🍕 Primera mitad
                        </label>
                        <select wire:model.live="halfAProductId"
                                style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid #d1d5db; font-size:14px; background:#ffffff; color:#111827;">
                            <option value="">— Elige el primer sabor —</option>
                            @foreach ($halfAOpts as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} (${{ number_format((float) $p->default_sale_price, 0) }})</option>
                            @endforeach
                        </select>
                        @if ($halfAOpts->isEmpty())
                            <div style="font-size:11px; color:#dc2626; margin-top:4px;">
                                No hay productos con categoría asignada. Asigna una categoría a tus pizzas/pastas primero.
                            </div>
                        @endif
                    </div>

                    {{-- Mitad B --}}
                    <div>
                        <label style="display:block; font-size:13px; font-weight:700; color:#111827; margin-bottom:6px;">
                            🍕 Segunda mitad
                            @if ($halfAProductId)
                                <span style="font-size:11px; color:#6b7280; font-weight:500;">(misma categoría)</span>
                            @endif
                        </label>
                        <select wire:model.live="halfBProductId"
                                @disabled(! $halfAProductId)
                                style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid #d1d5db; font-size:14px; background:{{ $halfAProductId ? '#ffffff' : '#f3f4f6' }}; color:#111827;">
                            <option value="">— Elige el segundo sabor —</option>
                            @foreach ($halfBOpts as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} (${{ number_format((float) $p->default_sale_price, 0) }})</option>
                            @endforeach
                        </select>
                        @if ($halfAProductId && $halfBOpts->isEmpty())
                            <div style="font-size:11px; color:#dc2626; margin-top:4px;">
                                No hay otros productos en esa categoría.
                            </div>
                        @endif
                    </div>

                    {{-- Preview --}}
                    @if ($preview)
                        <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:12px;">
                            <div style="font-size:12px; color:#15803d; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                                Vista previa
                            </div>
                            <div style="font-size:15px; font-weight:700; color:#111827; margin-bottom:6px;">
                                {{ $preview['description'] }}
                            </div>
                            <div style="font-size:11px; color:#374151; line-height:1.5;">
                                <div>· 1/2 {{ $preview['a'] }} → ${{ number_format($preview['price_a'], 0) }}</div>
                                <div>· 1/2 {{ $preview['b'] }} → ${{ number_format($preview['price_b'], 0) }}</div>
                            </div>
                            <div style="margin-top:8px; padding-top:8px; border-top:1px dashed #86efac; display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:12px; color:#374151; font-weight:600;">Precio (cobramos la mitad más cara)</span>
                                <span style="font-size:18px; font-weight:800; color:#059669;">${{ number_format($preview['final_price'], 0) }}</span>
                            </div>
                        </div>
                    @endif

                    {{-- Nota --}}
                    <div>
                        <label style="font-size:13px; font-weight:600; color:#111827; margin-bottom:6px; display:block;">
                            📝 Nota para cocina (opcional)
                        </label>
                        <textarea wire:model.live="halfNote"
                                  placeholder="Ej: borde de queso, sin aceitunas en la mitad de hawaiana..."
                                  rows="2"
                                  style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; resize:vertical; color:#111827; background:#ffffff;"></textarea>
                    </div>
                </div>

                {{-- Footer --}}
                <div style="padding:14px 22px; border-top:1px solid #e5e7eb; background:#fafafa; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" wire:click="closeHalfModal"
                            style="padding:10px 18px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-weight:600; cursor:pointer;">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmHalfAndHalf"
                            @disabled(! $halfAProductId || ! $halfBProductId)
                            style="padding:10px 22px; background:{{ $halfAProductId && $halfBProductId ? '#a855f7' : '#c4b5fd' }}; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer;">
                        ✓ Agregar 1/2 + 1/2
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ MODAL TRANSFERIR MESA ============ --}}
    @if ($transferModalOpen && $this->activeOrder)
        @php $availableTables = $this->transferTables; @endphp
        <div
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999; padding:20px;"
            wire:click.self="closeTransferModal"
            wire:keydown.escape.window="closeTransferModal"
        >
            <div style="background:#ffffff; border-radius:14px; max-width:520px; width:100%; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.4);">
                <div style="padding:18px 22px; border-bottom:1px solid #e5e7eb; background:#faf5ff; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2 style="margin:0; font-size:18px; font-weight:700; color:#581c87;">🔄 Transferir mesa</h2>
                        <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                            Mover orden de <strong>{{ $this->activeOrder->table?->code }}</strong> a otra mesa libre
                        </div>
                    </div>
                    <button type="button" wire:click="closeTransferModal"
                            style="background:transparent; border:0; cursor:pointer; padding:6px; font-size:24px; color:#6b7280; line-height:1;">×</button>
                </div>

                <div style="padding:18px 22px; overflow-y:auto; flex:1; color:#111827;">
                    @if ($availableTables->isEmpty())
                        <div style="padding:20px; text-align:center; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; color:#92400e; font-size:13px;">
                            No hay mesas libres en esta sede. Libera una mesa o usa "Juntar mesas" si querés combinar con otra orden.
                        </div>
                    @else
                        <label style="display:block; font-size:13px; font-weight:700; color:#111827; margin-bottom:8px;">Mesa destino</label>
                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap:6px;">
                            @foreach ($availableTables as $t)
                                @php $sel = (int) $transferTargetTableId === (int) $t->id; @endphp
                                <button type="button" wire:click="$set('transferTargetTableId', {{ $t->id }})"
                                        style="padding:10px 6px; border-radius:8px; border:2px solid {{ $sel ? '#a855f7' : '#d1d5db' }}; background:{{ $sel ? '#a855f7' : '#ffffff' }}; color:{{ $sel ? '#ffffff' : '#111827' }}; cursor:pointer; font-weight:700; font-size:14px; text-align:center;">
                                    {{ $t->code }}
                                    <div style="font-size:9px; opacity:0.8; font-weight:500; margin-top:2px;">{{ $t->zone?->name ?? '' }}</div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div style="padding:14px 22px; border-top:1px solid #e5e7eb; background:#fafafa; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" wire:click="closeTransferModal"
                            style="padding:10px 18px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-weight:600; cursor:pointer;">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmTransfer"
                            @disabled(! $transferTargetTableId)
                            style="padding:10px 22px; background:{{ $transferTargetTableId ? '#a855f7' : '#c4b5fd' }}; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer;">
                        ✓ Transferir
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ MODAL JUNTAR MESAS ============ --}}
    @if ($mergeModalOpen && $this->activeOrder)
        @php $mergeable = $this->mergeOrders; @endphp
        <div
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999; padding:20px;"
            wire:click.self="closeMergeModal"
            wire:keydown.escape.window="closeMergeModal"
        >
            <div style="background:#ffffff; border-radius:14px; max-width:560px; width:100%; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.4);">
                <div style="padding:18px 22px; border-bottom:1px solid #e5e7eb; background:#eff6ff; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2 style="margin:0; font-size:18px; font-weight:700; color:#1e3a8a;">🔗 Juntar mesas</h2>
                        <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                            Trae items de otra orden a <strong>{{ $this->activeOrder->table?->code }}</strong>. La mesa origen se libera.
                        </div>
                    </div>
                    <button type="button" wire:click="closeMergeModal"
                            style="background:transparent; border:0; cursor:pointer; padding:6px; font-size:24px; color:#6b7280; line-height:1;">×</button>
                </div>

                <div style="padding:18px 22px; overflow-y:auto; flex:1; color:#111827;">
                    @if ($mergeable->isEmpty())
                        <div style="padding:20px; text-align:center; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; color:#92400e; font-size:13px;">
                            No hay otras órdenes abiertas en esta sede.
                        </div>
                    @else
                        <label style="display:block; font-size:13px; font-weight:700; color:#111827; margin-bottom:8px;">Orden a fusionar (se vacía y libera su mesa)</label>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            @foreach ($mergeable as $o)
                                @php
                                    $sel = (int) $mergeTargetOrderId === (int) $o->id;
                                    $count = $o->items->reject(fn ($i) => $i->kitchen_status === 'cancelled')->count();
                                @endphp
                                <button type="button" wire:click="$set('mergeTargetOrderId', {{ $o->id }})"
                                        style="display:flex; justify-content:space-between; align-items:center; padding:12px; border-radius:8px; border:2px solid {{ $sel ? '#3b82f6' : '#d1d5db' }}; background:{{ $sel ? '#dbeafe' : '#ffffff' }}; cursor:pointer; text-align:left;">
                                    <div>
                                        <div style="font-size:14px; font-weight:700; color:#111827;">
                                            Mesa {{ $o->table?->code ?? '—' }}
                                        </div>
                                        <div style="font-size:11px; color:#6b7280; margin-top:2px;">
                                            {{ $o->fullNumber() }} · {{ $count }} item(s) · {{ $o->opened_at?->diffForHumans() }}
                                        </div>
                                    </div>
                                    <div style="font-size:14px; font-weight:700; color:#059669;">
                                        ${{ number_format((float) $o->total, 0, ',', '.') }}
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div style="padding:14px 22px; border-top:1px solid #e5e7eb; background:#fafafa; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" wire:click="closeMergeModal"
                            style="padding:10px 18px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-weight:600; cursor:pointer;">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmMerge"
                            @disabled(! $mergeTargetOrderId)
                            style="padding:10px 22px; background:{{ $mergeTargetOrderId ? '#3b82f6' : '#93c5fd' }}; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer;">
                        ✓ Juntar
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ MODAL NUEVA PARA LLEVAR ============ --}}
    @if ($takeawayModalOpen)
        <div
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999; padding:20px;"
            wire:click.self="closeTakeawayPrompt"
            wire:keydown.escape.window="closeTakeawayPrompt"
        >
            <div style="background:#ffffff; border-radius:14px; max-width:440px; width:100%; box-shadow:0 25px 50px rgba(0,0,0,0.4);">
                <div style="padding:18px 22px; border-bottom:1px solid #e5e7eb; background:#fff7ed; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2 style="margin:0; font-size:18px; font-weight:700; color:#9a3412;">🥡 Nueva orden para llevar</h2>
                        <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                            Sin mesa asignada — para pickup o llevar
                        </div>
                    </div>
                    <button type="button" wire:click="closeTakeawayPrompt"
                            style="background:transparent; border:0; cursor:pointer; padding:6px; font-size:24px; color:#6b7280; line-height:1;">×</button>
                </div>

                <div style="padding:18px 22px; color:#111827;">
                    <label style="display:block; font-size:13px; font-weight:700; color:#111827; margin-bottom:6px;">
                        Nombre del cliente (opcional)
                    </label>
                    <input type="text" wire:model.live="takeawayCustomerName"
                           placeholder="Ej: Juan, Pedido #43, Mesa 5 espera..."
                           autofocus
                           wire:keydown.enter="createTakeaway"
                           style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; color:#111827; background:#ffffff;" />
                    <div style="font-size:11px; color:#6b7280; margin-top:6px;">
                        Se mostrará en la lista de órdenes activas. Podés dejarlo vacío.
                    </div>
                </div>

                <div style="padding:14px 22px; border-top:1px solid #e5e7eb; background:#fafafa; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" wire:click="closeTakeawayPrompt"
                            style="padding:10px 18px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-weight:600; cursor:pointer;">
                        Cancelar
                    </button>
                    <button type="button" wire:click="createTakeaway"
                            style="padding:10px 22px; background:#ea580c; color:white; border:0; border-radius:8px; font-weight:700; cursor:pointer;">
                        ✓ Abrir orden
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ MODAL DE COBRO ============ --}}
    @if ($billingModalOpen && $this->activeOrder)
        @php
            $billTabs = $this->billingTabs;
            $methods = $this->paymentMethodOptions;
            $accounts = $this->cashAccountOptions;
            $orderTotal = (float) $this->activeOrder->total;
        @endphp
        <div
            style="position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:9999; padding:20px;"
            wire:click.self="closeBillingModal"
            wire:keydown.escape.window="closeBillingModal"
        >
            <div style="background:#ffffff; border-radius:14px; max-width:720px; width:100%; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.5);">

                <div style="padding:18px 22px; border-bottom:1px solid #e5e7eb; background:#ecfdf5; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2 style="margin:0; font-size:18px; font-weight:700; color:#065f46;">
                            💵 Cobrar cuenta
                        </h2>
                        <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                            {{ count($billTabs) }} {{ count($billTabs) === 1 ? 'factura' : 'facturas' }} a generar · Total: ${{ number_format($orderTotal, 0, ',', '.') }}
                        </div>
                    </div>
                    <button type="button" wire:click="closeBillingModal"
                            style="background:transparent; border:0; cursor:pointer; padding:6px; font-size:24px; color:#6b7280; line-height:1;">×</button>
                </div>

                <div style="padding:18px 22px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:14px; color:#111827;">
                    @foreach ($billTabs as $t)
                        <div style="border:1px solid #d1d5db; border-radius:10px; padding:14px; background:#fafafa;">
                            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:10px;">
                                <div style="font-size:15px; font-weight:700; color:#111827;">
                                    @if ($t['label'])
                                        🪙 Tab {{ $t['label'] }}
                                    @else
                                        📋 Cuenta completa
                                    @endif
                                    <span style="font-size:11px; font-weight:500; color:#6b7280;">({{ $t['items']->count() }} items)</span>
                                </div>
                            </div>

                            {{-- Items del tab --}}
                            <div style="background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; padding:8px; margin-bottom:10px; max-height:160px; overflow-y:auto;">
                                @foreach ($t['items'] as $it)
                                    <div style="display:flex; justify-content:space-between; font-size:12px; color:#374151; padding:3px 0;">
                                        <span>{{ number_format((float) $it->quantity, 0) }} × {{ $it->description }}</span>
                                        <span style="font-weight:600; color:#111827;">${{ number_format((float) $it->total, 0, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Desglose --}}
                            <div style="font-size:12px; color:#374151; display:flex; flex-direction:column; gap:3px; margin-bottom:10px;">
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Subtotal</span>
                                    <span>${{ number_format($t['subtotal'], 0, ',', '.') }}</span>
                                </div>
                                @if ($t['tax'] > 0)
                                    <div style="display:flex; justify-content:space-between;">
                                        <span>IVA</span>
                                        <span>${{ number_format($t['tax'], 0, ',', '.') }}</span>
                                    </div>
                                @endif
                                <div style="display:flex; justify-content:space-between; font-weight:700; padding-top:3px; border-top:1px dashed #d1d5db;">
                                    <span>Factura (sin propina)</span>
                                    <span style="color:#1e40af;">${{ number_format($t['invoice_total'], 0, ',', '.') }}</span>
                                </div>
                                @if ($t['tip_share'] > 0)
                                    <div style="display:flex; justify-content:space-between; color:#92400e;">
                                        <span>+ Propina (no facturada)</span>
                                        <span>${{ number_format($t['tip_share'], 0, ',', '.') }}</span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-weight:800; font-size:14px; padding-top:4px; border-top:1px solid #d1d5db; color:#059669;">
                                        <span>Total a recibir</span>
                                        <span>${{ number_format($t['grand_total'], 0, ',', '.') }}</span>
                                    </div>
                                @endif
                            </div>

                            {{-- Método y cuenta --}}
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                                <div>
                                    <label style="font-size:11px; font-weight:600; color:#374151; margin-bottom:3px; display:block;">Método de pago</label>
                                    <select wire:model.live="billingMethods.{{ $t['key'] }}"
                                            wire:change="onBillingMethodChange('{{ $t['key'] }}', $event.target.value)"
                                            style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid #d1d5db; font-size:13px; color:#111827; background:#ffffff;">
                                        @foreach ($methods as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px; font-weight:600; color:#374151; margin-bottom:3px; display:block;">Cuenta contable</label>
                                    <select wire:model.live="billingAccounts.{{ $t['key'] }}"
                                            style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid #d1d5db; font-size:13px; color:#111827; background:#ffffff;">
                                        <option value="">— Elige cuenta —</option>
                                        @foreach ($accounts as $acc)
                                            <option value="{{ $acc->id }}">{{ $acc->code }} — {{ $acc->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    {{-- Referencia común --}}
                    <div>
                        <label style="font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; display:block;">
                            Referencia (opcional)
                        </label>
                        <input type="text" wire:model="billingReference"
                               placeholder="Ej: voucher tarjeta #1234, transferencia, etc."
                               style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid #d1d5db; font-size:13px; color:#111827; background:#ffffff;" />
                    </div>

                    {{-- Aviso de propina --}}
                    @if ((float) $this->activeOrder->tip_amount > 0)
                        <div style="background:#fefce8; border:1px solid #fde047; border-radius:8px; padding:10px; font-size:11px; color:#713f12; line-height:1.5;">
                            ℹ️ <strong>Propina:</strong> ${{ number_format((float) $this->activeOrder->tip_amount, 0, ',', '.') }} se recibe pero NO va en la factura (CO: la propina es voluntaria y sin IVA). Queda registrada en la orden y aparece en el reporte de propinas por mesero.
                        </div>
                    @endif
                </div>

                <div style="padding:14px 22px; border-top:1px solid #e5e7eb; background:#fafafa; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" wire:click="closeBillingModal"
                            style="padding:10px 18px; background:transparent; color:#6b7280; border:1px solid #d1d5db; border-radius:8px; font-weight:600; cursor:pointer;">
                        Cancelar
                    </button>
                    <button type="button" wire:click="confirmBilling"
                            wire:loading.attr="disabled"
                            wire:target="confirmBilling"
                            style="padding:10px 22px; background:#10b981; color:white; border:0; border-radius:8px; font-weight:800; cursor:pointer;">
                        <span wire:loading.remove wire:target="confirmBilling">✓ Facturar y cerrar</span>
                        <span wire:loading wire:target="confirmBilling">Procesando...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
