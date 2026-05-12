<x-filament-panels::page>
    @php
        $locations = $this->getLocations();
        $zones = $this->getZones();
        $tables = $this->getTables();
    @endphp

    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; padding:12px; border-radius:12px; background:rgb(243,244,246); margin-bottom:16px;"
         class="dark:!bg-gray-900">

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; font-weight:600; text-transform:uppercase; opacity:0.7;">Sede</label>
            <select wire:model.live="locationId" style="padding:6px 10px; border-radius:8px; border:1px solid #d1d5db; background:white; font-size:14px;"
                    class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-white">
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; font-weight:600; text-transform:uppercase; opacity:0.7;">Zona</label>
            <select wire:model.live="zoneId" style="padding:6px 10px; border-radius:8px; border:1px solid #d1d5db; background:white; font-size:14px;"
                    class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-white">
                <option value="">— Todas las zonas —</option>
                @foreach ($zones as $z)
                    <option value="{{ $z->id }}">{{ $z->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="margin-left:auto; font-size:13px; color:#6b7280;" class="dark:!text-gray-400">
            <span style="font-weight:600;">Arrastra</span> las mesas para reubicarlas. Se guarda automáticamente.
        </div>
    </div>

    {{-- Lienzo del mapa: viewBox 0-1000 escala a 100% del contenedor --}}
    <div style="position:relative; width:100%; aspect-ratio: 16/9; min-height:500px; background:repeating-linear-gradient(0deg, rgba(0,0,0,0.04), rgba(0,0,0,0.04) 1px, transparent 1px, transparent 50px), repeating-linear-gradient(90deg, rgba(0,0,0,0.04), rgba(0,0,0,0.04) 1px, transparent 1px, transparent 50px); border-radius:12px; border:1px solid #d1d5db; overflow:hidden;"
         class="dark:!border-gray-700"
         x-data="tableMap()"
         x-init="init"
         id="restaurant-map-canvas">

        @foreach ($tables as $table)
            @php
                $bg = match ($table->status) {
                    'free' => 'rgb(16, 185, 129)',
                    'occupied' => 'rgb(245, 158, 11)',
                    'billing' => 'rgb(59, 130, 246)',
                    'reserved' => 'rgb(168, 85, 247)',
                    'cleaning' => 'rgb(107, 114, 128)',
                    default => 'rgb(107, 114, 128)',
                };
                $zoneColor = $table->zone?->color ?? '#3b82f6';
                $borderRadius = match ($table->shape) {
                    'round' => '50%',
                    'bar' => '6px',
                    default => '10px',
                };
            @endphp

            <div class="map-table"
                 data-table-id="{{ $table->id }}"
                 data-x="{{ $table->pos_x }}"
                 data-y="{{ $table->pos_y }}"
                 style="
                     position:absolute;
                     left:{{ $table->pos_x / 10 }}%;
                     top:{{ $table->pos_y / 10 }}%;
                     width:{{ $table->width }}px;
                     height:{{ $table->height }}px;
                     background:{{ $bg }};
                     border:3px solid {{ $zoneColor }};
                     border-radius:{{ $borderRadius }};
                     display:flex; flex-direction:column; align-items:center; justify-content:center;
                     color:white; font-weight:700; font-size:14px;
                     cursor:grab; user-select:none;
                     box-shadow:0 4px 6px rgba(0,0,0,0.1);
                     transition: box-shadow 150ms;
                 "
                 onmouseover="this.style.boxShadow='0 8px 16px rgba(0,0,0,0.2)'"
                 onmouseout="this.style.boxShadow='0 4px 6px rgba(0,0,0,0.1)'">
                <div style="font-size:16px;">{{ $table->code }}</div>
                <div style="font-size:10px; opacity:0.85;">{{ $table->capacity }}p</div>
            </div>
        @endforeach
    </div>

    <div style="margin-top:16px; padding:12px; border-radius:8px; background:rgb(243,244,246); font-size:13px;"
         class="dark:!bg-gray-900">
        <strong>Leyenda:</strong>
        <span style="display:inline-block; width:14px; height:14px; background:rgb(16,185,129); border-radius:3px; vertical-align:middle; margin:0 4px;"></span> Libre
        <span style="display:inline-block; width:14px; height:14px; background:rgb(245,158,11); border-radius:3px; vertical-align:middle; margin:0 4px;"></span> Ocupada
        <span style="display:inline-block; width:14px; height:14px; background:rgb(59,130,246); border-radius:3px; vertical-align:middle; margin:0 4px;"></span> Cuenta
        <span style="display:inline-block; width:14px; height:14px; background:rgb(168,85,247); border-radius:3px; vertical-align:middle; margin:0 4px;"></span> Reservada
        <span style="display:inline-block; width:14px; height:14px; background:rgb(107,114,128); border-radius:3px; vertical-align:middle; margin:0 4px;"></span> Limpieza
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
                    });

                    document.addEventListener('mousemove', (e) => {
                        if (! self.dragging) return;
                        const canvasRect = canvas.getBoundingClientRect();
                        const x = e.clientX - canvasRect.left - self.offsetX;
                        const y = e.clientY - canvasRect.top - self.offsetY;
                        const xPct = Math.max(0, Math.min(100, (x / canvasRect.width) * 100));
                        const yPct = Math.max(0, Math.min(100, (y / canvasRect.height) * 100));
                        self.dragging.style.left = xPct + '%';
                        self.dragging.style.top = yPct + '%';
                    });

                    document.addEventListener('mouseup', (e) => {
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
                    });
                }
            }
        }
    </script>
</x-filament-panels::page>
