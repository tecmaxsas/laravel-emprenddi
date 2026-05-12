<x-filament-panels::page>
    @php
        $printers = $this->printers;
        $received = $this->receivedItems;
        $preparing = $this->preparingItems;
        $ready = $this->readyItems;

        $now = now();
        $elapsed = function ($timestamp) use ($now) {
            if (! $timestamp) return '—';
            $mins = (int) $now->diffInMinutes(\Illuminate\Support\Carbon::parse($timestamp));
            if ($mins < 1) return 'ahora';
            if ($mins < 60) return $mins.' min';
            $hrs = floor($mins / 60);
            $r = $mins % 60;
            return $hrs.'h '.$r.'m';
        };
    @endphp

    <style>
        .kds-col { background:#f9fafb; border:1px solid #e5e7eb; border-radius:14px; padding:14px; display:flex; flex-direction:column; gap:10px; min-height:60vh; }
        :is(.dark) .kds-col { background:rgb(17,24,39); border-color:rgb(31,41,55); }
        .kds-col-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:10px; border-bottom:2px solid; }
        .kds-card { background:#ffffff; border-radius:12px; padding:14px; border-left:5px solid; box-shadow:0 1px 3px rgba(0,0,0,0.08); display:flex; flex-direction:column; gap:8px; }
        :is(.dark) .kds-card { background:rgb(31,41,55); }
        .kds-card-old { animation: kdsPulse 2s ease-in-out infinite; }
        @keyframes kdsPulse {
            0%, 100% { box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
            50% { box-shadow: 0 0 0 4px rgba(239,68,68,0.3); }
        }
        .kds-btn { padding:10px 14px; border:0; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; transition:transform 100ms; }
        .kds-btn:active { transform:scale(0.96); }
        .kds-btn-blue { background:#3b82f6; color:white; }
        .kds-btn-amber { background:#f59e0b; color:white; }
        .kds-btn-green { background:#10b981; color:white; }
        .kds-btn-ghost { background:transparent; color:#6b7280; padding:6px 10px; font-size:11px; }
        :is(.dark) .kds-btn-ghost { color:#9ca3af; }
        .kds-badge { font-size:10px; font-weight:700; padding:3px 8px; border-radius:999px; }
    </style>

    {{-- ============ HEADER ============ --}}
    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; padding:14px 16px; background:#f3f4f6; border-radius:12px; border:1px solid #e5e7eb; margin-bottom:16px;"
         class="dark:!bg-gray-900 dark:!border-gray-800">

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase;" class="dark:!text-gray-400">Estación</label>
            <select wire:model.live="printerId"
                    style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#ffffff; color:#111827; font-size:14px; min-width:200px; height:38px;"
                    class="dark:!bg-gray-800 dark:!border-gray-700 dark:!text-gray-100">
                <option value="">— Todas (cocina + barra) —</option>
                @foreach ($printers as $p)
                    <option value="{{ $p->id }}">{{ $p->name }} ({{ \App\Models\Restaurant\Printer::PURPOSES[$p->purpose] ?? $p->purpose }})</option>
                @endforeach
            </select>
        </div>

        <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:#374151;" class="dark:!text-gray-300">
            <input type="checkbox" wire:model.live="autoRefresh" style="width:16px; height:16px;" />
            Auto-refrescar (5s)
        </label>

        <div style="margin-left:auto; display:flex; gap:10px; font-size:12px; color:#6b7280;" class="dark:!text-gray-400">
            <span><strong style="color:#3b82f6;">{{ $received->count() }}</strong> recibido</span>
            <span><strong style="color:#f59e0b;">{{ $preparing->count() }}</strong> preparando</span>
            <span><strong style="color:#10b981;">{{ $ready->count() }}</strong> listo</span>
        </div>
    </div>

    {{-- ============ COLUMNAS (con polling) ============ --}}
    <div @if($autoRefresh) wire:poll.5s @endif
         style="display:grid; grid-template-columns: repeat(3, 1fr); gap:16px;">

        {{-- COL 1 — RECIBIDO --}}
        <div class="kds-col">
            <div class="kds-col-header" style="border-color:#3b82f6;">
                <h3 style="margin:0; font-size:16px; font-weight:700; color:#1e40af;">🆕 Recibido</h3>
                <span class="kds-badge" style="background:#dbeafe; color:#1e40af;">{{ $received->count() }}</span>
            </div>

            @forelse ($received as $item)
                @php
                    $mins = (int) $now->diffInMinutes(\Illuminate\Support\Carbon::parse($item->sent_to_kitchen_at ?? $item->created_at));
                    $isOld = $mins >= 10;
                @endphp
                <div class="kds-card {{ $isOld ? 'kds-card-old' : '' }}" style="border-left-color:#3b82f6;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-size:16px; font-weight:800; color:#111827;" class="dark:!text-gray-100">
                            Mesa {{ $item->order->table?->code ?? ($item->order->is_delivery ? 'DELIV' : '—') }}
                        </div>
                        <span class="kds-badge" style="background:{{ $isOld ? '#fee2e2' : '#dbeafe' }}; color:{{ $isOld ? '#991b1b' : '#1e40af' }};">
                            {{ $elapsed($item->sent_to_kitchen_at ?? $item->created_at) }}
                        </span>
                    </div>
                    <div style="font-size:18px; font-weight:700; line-height:1.2;">
                        {{ number_format((float) $item->quantity, 0) }} × {{ strtoupper($item->description) }}
                    </div>
                    @if (! empty($item->modifiers))
                        <div style="font-size:12px; color:#6b7280;" class="dark:!text-gray-400">
                            @foreach ($item->modifiers as $mod)
                                @php $modName = is_array($mod) ? ($mod['name'] ?? '') : (string)$mod; @endphp
                                @if ($modName) <div>• {{ $modName }}</div> @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($item->item_note)
                        <div style="font-size:12px; color:#92400e; background:#fef3c7; padding:6px 8px; border-radius:6px;">
                            ⚠️ {{ $item->item_note }}
                        </div>
                    @endif
                    <button type="button" wire:click="startPreparing({{ $item->id }})" class="kds-btn kds-btn-amber" style="margin-top:4px;">
                        🔥 Empezar a preparar →
                    </button>
                </div>
            @empty
                <div style="text-align:center; padding:40px 20px; color:#9ca3af; font-size:13px;">Sin items pendientes.</div>
            @endforelse
        </div>

        {{-- COL 2 — PREPARANDO --}}
        <div class="kds-col">
            <div class="kds-col-header" style="border-color:#f59e0b;">
                <h3 style="margin:0; font-size:16px; font-weight:700; color:#92400e;">🔥 Preparando</h3>
                <span class="kds-badge" style="background:#fef3c7; color:#92400e;">{{ $preparing->count() }}</span>
            </div>

            @forelse ($preparing as $item)
                @php
                    $mins = (int) $now->diffInMinutes(\Illuminate\Support\Carbon::parse($item->preparing_at ?? $item->sent_to_kitchen_at ?? $item->created_at));
                    $isSlow = $mins >= 15;
                @endphp
                <div class="kds-card {{ $isSlow ? 'kds-card-old' : '' }}" style="border-left-color:#f59e0b;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-size:16px; font-weight:800;">
                            Mesa {{ $item->order->table?->code ?? '—' }}
                        </div>
                        <span class="kds-badge" style="background:{{ $isSlow ? '#fee2e2' : '#fef3c7' }}; color:{{ $isSlow ? '#991b1b' : '#92400e' }};">
                            {{ $elapsed($item->preparing_at) }}
                        </span>
                    </div>
                    <div style="font-size:18px; font-weight:700; line-height:1.2;">
                        {{ number_format((float) $item->quantity, 0) }} × {{ strtoupper($item->description) }}
                    </div>
                    @if ($item->item_note)
                        <div style="font-size:12px; color:#92400e; background:#fef3c7; padding:6px 8px; border-radius:6px;">⚠️ {{ $item->item_note }}</div>
                    @endif
                    <div style="display:flex; gap:6px; margin-top:4px;">
                        <button type="button" wire:click="regressItem({{ $item->id }})" class="kds-btn kds-btn-ghost" title="Volver a recibido">↶</button>
                        <button type="button" wire:click="markReady({{ $item->id }})" class="kds-btn kds-btn-green" style="flex:1;">
                            ✓ Listo →
                        </button>
                    </div>
                </div>
            @empty
                <div style="text-align:center; padding:40px 20px; color:#9ca3af; font-size:13px;">Sin items en preparación.</div>
            @endforelse
        </div>

        {{-- COL 3 — LISTO --}}
        <div class="kds-col">
            <div class="kds-col-header" style="border-color:#10b981;">
                <h3 style="margin:0; font-size:16px; font-weight:700; color:#065f46;">✓ Listo para servir</h3>
                <span class="kds-badge" style="background:#d1fae5; color:#065f46;">{{ $ready->count() }}</span>
            </div>

            @forelse ($ready as $item)
                @php
                    $mins = (int) $now->diffInMinutes(\Illuminate\Support\Carbon::parse($item->ready_at ?? now()));
                    $isStale = $mins >= 5;
                @endphp
                <div class="kds-card {{ $isStale ? 'kds-card-old' : '' }}" style="border-left-color:#10b981;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-size:16px; font-weight:800;">
                            Mesa {{ $item->order->table?->code ?? '—' }}
                        </div>
                        <span class="kds-badge" style="background:{{ $isStale ? '#fee2e2' : '#d1fae5' }}; color:{{ $isStale ? '#991b1b' : '#065f46' }};">
                            {{ $elapsed($item->ready_at) }}
                        </span>
                    </div>
                    <div style="font-size:18px; font-weight:700; line-height:1.2;">
                        {{ number_format((float) $item->quantity, 0) }} × {{ strtoupper($item->description) }}
                    </div>
                    <div style="font-size:12px; color:#6b7280;" class="dark:!text-gray-400">
                        Mesero: {{ $item->order->server?->name ?? '—' }}
                    </div>
                    <div style="display:flex; gap:6px; margin-top:4px;">
                        <button type="button" wire:click="regressItem({{ $item->id }})" class="kds-btn kds-btn-ghost" title="Volver a preparando">↶</button>
                        <button type="button" wire:click="markServed({{ $item->id }})" class="kds-btn kds-btn-blue" style="flex:1;">
                            🍽️ Servido al cliente
                        </button>
                    </div>
                </div>
            @empty
                <div style="text-align:center; padding:40px 20px; color:#9ca3af; font-size:13px;">Sin items listos.</div>
            @endforelse
        </div>
    </div>

    {{-- Hint para la primera vez --}}
    @if ($received->isEmpty() && $preparing->isEmpty() && $ready->isEmpty())
        <div style="margin-top:20px; padding:14px; background:#eff6ff; border-radius:10px; color:#1e40af; font-size:13px; border:1px solid #bfdbfe;">
            <strong>Sin actividad en cocina.</strong> Los items aparecerán aquí automáticamente cuando los meseros toquen "Enviar a cocina" desde el POS Restaurante.
            @if ($printerId)
                Esta vista está filtrada por una estación específica — prueba con "Todas" para ver todo el flujo.
            @endif
        </div>
    @endif
</x-filament-panels::page>
