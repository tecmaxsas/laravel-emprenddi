<x-filament-panels::page>
    @php
        $locations = $this->getLocations();
        $zones = $this->getZones();
        $tables = $this->getTables();
    @endphp

    {{-- ============================================================ --}}
    {{-- FILTROS                                                       --}}
    {{-- ============================================================ --}}
    <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; padding:16px; border-radius:12px; background:#f3f4f6; border:1px solid #e5e7eb; margin-bottom:16px;"
         class="dark:!bg-gray-900 dark:!border-gray-800">

        <div style="display:flex; flex-direction:column; gap:6px; min-width:220px;">
            <label style="font-size:12px; font-weight:600; color:#6b7280;" class="dark:!text-gray-400">Sede</label>
            <select wire:model.live="locationId"
                    style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#ffffff; color:#111827; font-size:14px; min-width:220px; height:38px;"
                    class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-gray-100">
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:6px; min-width:220px;">
            <label style="font-size:12px; font-weight:600; color:#6b7280;" class="dark:!text-gray-400">Zona</label>
            <select wire:model.live="zoneId"
                    style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#ffffff; color:#111827; font-size:14px; min-width:220px; height:38px;"
                    class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-gray-100">
                <option value="">— Todas las zonas —</option>
                @foreach ($zones as $z)
                    <option value="{{ $z->id }}">{{ $z->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="margin-left:auto; font-size:13px; color:#6b7280; max-width:340px; text-align:right;"
             class="dark:!text-gray-400">
            <strong style="color:#111827;" class="dark:!text-gray-100">Tip:</strong>
            arrastra las mesas en el mapa para reubicarlas.<br>Se guarda automáticamente al soltar.
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- LIENZO DEL MAPA                                               --}}
    {{-- ============================================================ --}}
    @if ($tables->isEmpty())
        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:80px 20px; border-radius:12px; border:2px dashed #d1d5db; background:#ffffff; gap:12px;"
             class="dark:!bg-gray-900 dark:!border-gray-700">
            <svg style="width:56px; height:56px; color:#9ca3af;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5"/>
            </svg>
            <div style="font-size:16px; font-weight:600; color:#374151;" class="dark:!text-gray-300">
                No hay mesas creadas
            </div>
            <p style="font-size:13px; color:#6b7280; text-align:center; max-width:480px;" class="dark:!text-gray-400">
                Primero crea mesas en <strong>Mesas → Nueva mesa</strong> (define código, capacidad, zona). Después regresa aquí para acomodarlas visualmente en el mapa.
            </p>
        </div>
    @else
        <div style="position:relative; width:100%; height:600px; border-radius:12px; border:1px solid #d1d5db; overflow:hidden;
                    background-color:#fafafa;
                    background-image:
                        linear-gradient(rgba(0,0,0,0.07) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(0,0,0,0.07) 1px, transparent 1px);
                    background-size:50px 50px;"
             class="dark:!border-gray-700 dark:!bg-gray-950"
             x-data="tableMap()"
             x-init="init"
             id="restaurant-map-canvas">

            @foreach ($tables as $table)
                @php
                    $bg = match ($table->status) {
                        'free' => '#10b981',
                        'occupied' => '#f59e0b',
                        'billing' => '#3b82f6',
                        'reserved' => '#a855f7',
                        'cleaning' => '#6b7280',
                        default => '#6b7280',
                    };
                    $zoneColor = $table->zone?->color ?? '#3b82f6';
                    $borderRadius = match ($table->shape) {
                        'round' => '50%',
                        'bar' => '6px',
                        default => '10px',
                    };
                    $w = max(60, min(180, (int) $table->width));
                    $h = max(60, min(180, (int) $table->height));
                @endphp

                <div class="map-table"
                     data-table-id="{{ $table->id }}"
                     style="
                         position:absolute;
                         left:{{ $table->pos_x / 10 }}%;
                         top:{{ $table->pos_y / 10 }}%;
                         width:{{ $w }}px;
                         height:{{ $h }}px;
                         background:{{ $bg }};
                         border:3px solid {{ $zoneColor }};
                         border-radius:{{ $borderRadius }};
                         display:flex; flex-direction:column; align-items:center; justify-content:center;
                         color:#ffffff; font-weight:700;
                         cursor:grab; user-select:none;
                         box-shadow:0 4px 6px rgba(0,0,0,0.15);
                         transition:box-shadow 150ms;
                         text-shadow:0 1px 2px rgba(0,0,0,0.3);
                     "
                     onmouseover="this.style.boxShadow='0 8px 16px rgba(0,0,0,0.25)'"
                     onmouseout="this.style.boxShadow='0 4px 6px rgba(0,0,0,0.15)'"
                     title="{{ $table->code }} — {{ $table->label ?: ($table->zone?->name ?? 'Sin zona') }}">
                    <div style="font-size:16px; line-height:1;">{{ $table->code }}</div>
                    <div style="font-size:11px; opacity:0.9; margin-top:3px;">{{ $table->capacity }}p</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- LEYENDA                                                       --}}
    {{-- ============================================================ --}}
    <div style="margin-top:16px; padding:14px 16px; border-radius:8px; background:#f3f4f6; font-size:13px; display:flex; flex-wrap:wrap; gap:16px; align-items:center;"
         class="dark:!bg-gray-900">
        <strong style="color:#111827;" class="dark:!text-gray-100">Estados:</strong>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="display:inline-block; width:14px; height:14px; background:#10b981; border-radius:3px;"></span> Libre
        </span>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="display:inline-block; width:14px; height:14px; background:#f59e0b; border-radius:3px;"></span> Ocupada
        </span>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="display:inline-block; width:14px; height:14px; background:#3b82f6; border-radius:3px;"></span> Cuenta
        </span>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="display:inline-block; width:14px; height:14px; background:#a855f7; border-radius:3px;"></span> Reservada
        </span>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="display:inline-block; width:14px; height:14px; background:#6b7280; border-radius:3px;"></span> Limpieza
        </span>
        <span style="margin-left:auto; color:#6b7280; font-size:12px;" class="dark:!text-gray-400">
            Borde = color de la zona
        </span>
    </div>

    <script>
        function tableMap() {
            return {
                dragging: null,
                offsetX: 0,
                offsetY: 0,
                init() {
                    const canvas = this.$el;
                    const self = this;

                    canvas.querySelectorAll('.map-table').forEach(el => {
                        el.addEventListener('mousedown', (e) => {
                            self.dragging = el;
                            el.style.cursor = 'grabbing';
                            el.style.zIndex = '999';
                            const rect = el.getBoundingClientRect();
                            self.offsetX = e.clientX - rect.left;
                            self.offsetY = e.clientY - rect.top;
                            e.preventDefault();
                        });

                        el.addEventListener('touchstart', (e) => {
                            const t = e.touches[0];
                            self.dragging = el;
                            el.style.cursor = 'grabbing';
                            el.style.zIndex = '999';
                            const rect = el.getBoundingClientRect();
                            self.offsetX = t.clientX - rect.left;
                            self.offsetY = t.clientY - rect.top;
                        }, { passive: true });
                    });

                    const moveHandler = (clientX, clientY) => {
                        if (! self.dragging) return;
                        const canvasRect = canvas.getBoundingClientRect();
                        const x = clientX - canvasRect.left - self.offsetX;
                        const y = clientY - canvasRect.top - self.offsetY;
                        const xPct = Math.max(0, Math.min(100, (x / canvasRect.width) * 100));
                        const yPct = Math.max(0, Math.min(100, (y / canvasRect.height) * 100));
                        self.dragging.style.left = xPct + '%';
                        self.dragging.style.top = yPct + '%';
                    };

                    document.addEventListener('mousemove', (e) => moveHandler(e.clientX, e.clientY));
                    document.addEventListener('touchmove', (e) => {
                        if (! self.dragging) return;
                        moveHandler(e.touches[0].clientX, e.touches[0].clientY);
                    }, { passive: true });

                    const endHandler = () => {
                        if (! self.dragging) return;
                        const el = self.dragging;
                        el.style.cursor = 'grab';
                        el.style.zIndex = 'auto';

                        const canvasRect = canvas.getBoundingClientRect();
                        const rect = el.getBoundingClientRect();
                        const xPct = ((rect.left - canvasRect.left) / canvasRect.width) * 100;
                        const yPct = ((rect.top - canvasRect.top) / canvasRect.height) * 100;
                        const x1000 = Math.round(xPct * 10);
                        const y1000 = Math.round(yPct * 10);

                        const tableId = parseInt(el.dataset.tableId);
                        @this.call('savePosition', tableId, x1000, y1000);

                        self.dragging = null;
                    };

                    document.addEventListener('mouseup', endHandler);
                    document.addEventListener('touchend', endHandler);
                }
            }
        }
    </script>
</x-filament-panels::page>
