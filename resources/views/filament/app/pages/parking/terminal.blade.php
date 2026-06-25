@php
    $fmt = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');

    $lots = $this->lots;
    $stats = $this->occupancyStats;
    $spacesByZone = $this->spacesByZone;
    $activeWithoutSpace = $this->activeWithoutSpace;
    $cashSession = $this->openCashSession;
    $currentLot = $this->currentLot;
    $vehicleTypes = $this->vehicleTypes;
    $selectedSpace = $this->selectedSpace;
    $activeSession = $this->activeSession;

    $tileColor = fn ($status) => match ($status) {
        'free' => '#16a34a',
        'occupied' => '#dc2626',
        'reserved' => '#f59e0b',
        'maintenance' => '#6b7280',
        default => '#6b7280',
    };
@endphp

<x-filament-panels::page>
    {{-- Banner del turno --}}
    @if ($cashSession)
        <div style="border:1px solid #16a34a; background:#dcfce7; color:#166534; border-radius:8px; padding:8px 14px; font-size:12.5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
            <div>💰 <strong>Turno abierto</strong> · {{ $cashSession->opened_at->format('d/m/Y H:i') }} · Base {{ $fmt($cashSession->opening_amount) }} · Sede {{ $cashSession->location?->name ?? '—' }}</div>
        </div>
    @else
        <div style="border:1px solid #dc2626; background:#fee2e2; color:#991b1b; border-radius:8px; padding:10px 14px; font-size:13px; margin-bottom:12px;">
            🚨 <strong>Sin turno de caja abierto.</strong> Las salidas con cobro fallarán hasta que abras caja.
            <a href="{{ route('filament.app.pages.pos') }}" style="color:#991b1b; text-decoration:underline; font-weight:700; margin-left:6px;">Abrir caja</a>
        </div>
    @endif

    {{-- Top bar: lot picker + scan + KPIs --}}
    <div class="pt-bar">
        <div class="pt-lot-picker">
            <label>Parqueadero</label>
            <div class="pt-lot-tabs">
                @foreach ($lots as $lot)
                    <button type="button" wire:click="changeLot({{ $lot->id }})"
                        class="pt-lot-tab @if ($lot->id === $parkingLotId) is-active @endif">
                        {{ $lot->name }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="pt-scan">
            <label>Escanear ticket o digitar placa</label>
            <input type="text" wire:model="scanInput" wire:keydown.enter="handleScan"
                placeholder="QR del ticket o placa" autofocus
                class="pt-scan-input" />
            <span class="pt-scan-hint">Enter para procesar</span>
        </div>

        <div class="pt-kpis">
            <div class="pt-kpi pt-kpi-green">
                <span class="pt-kpi-num">{{ $stats['free'] }}</span>
                <span class="pt-kpi-label">Libres</span>
            </div>
            <div class="pt-kpi pt-kpi-red">
                <span class="pt-kpi-num">{{ $stats['occupied'] }}</span>
                <span class="pt-kpi-label">Ocupados</span>
            </div>
            <div class="pt-kpi pt-kpi-gray">
                <span class="pt-kpi-num">{{ $stats['total'] }}</span>
                <span class="pt-kpi-label">Total</span>
            </div>
        </div>

        <button type="button" wire:click="openQuickEntry" class="pt-quick-entry">
            ➕ Entrada sin espacio
        </button>
    </div>

    {{-- Mapa --}}
    @if (! $parkingLotId)
        <div class="pt-empty">Selecciona un parqueadero para ver el mapa.</div>
    @elseif (empty($spacesByZone))
        <div class="pt-empty">
            Este parqueadero no tiene espacios configurados. Crea espacios en <strong>Parqueadero → Espacios</strong>
            o usa <strong>Entrada sin espacio</strong> para registros plate-only.
        </div>
    @else
        @foreach ($spacesByZone as $zoneName => $spaces)
            <div class="pt-zone">
                <div class="pt-zone-head">
                    <h3>{{ $zoneName }}</h3>
                    <span class="pt-zone-count">{{ count($spaces) }} espacios</span>
                </div>
                <div class="pt-grid">
                    @foreach ($spaces as $space)
                        @php
                            $color = $tileColor($space->status);
                            $isOcc = $space->status === \App\Models\Parking\ParkingSpace::STATUS_OCCUPIED;
                            $sess = $space->activeSession->first();
                            $isFree = $space->status === \App\Models\Parking\ParkingSpace::STATUS_FREE;
                            $clickable = $isFree || $isOcc;
                        @endphp
                        <button type="button"
                            @if ($clickable) wire:click="selectSpace({{ $space->id }})" @else disabled @endif
                            class="pt-tile {{ $clickable ? '' : 'pt-tile-disabled' }}"
                            style="background:{{ $color }};"
                            title="{{ $isOcc && $sess ? "Placa {$sess->plate} · entró ".$sess->entry_at->format('H:i') : (\App\Models\Parking\ParkingSpace::STATUSES[$space->status] ?? '') }}">
                            <div class="pt-tile-code">{{ $space->code }}</div>
                            @if ($space->is_accessibility)
                                <div class="pt-tile-acc">♿</div>
                            @endif
                            @if ($isOcc && $sess)
                                <div class="pt-tile-plate">{{ $sess->plate }}</div>
                                <div class="pt-tile-time">{{ $sess->entry_at->format('H:i') }}</div>
                            @elseif ($space->vehicleType)
                                <div class="pt-tile-vt">{{ $space->vehicleType->name }}</div>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    {{-- Lista de activos sin espacio --}}
    @if ($activeWithoutSpace->isNotEmpty())
        <div class="pt-floating">
            <div class="pt-floating-head">
                <h3>Vehículos activos sin espacio asignado</h3>
                <span class="pt-floating-count">{{ $activeWithoutSpace->count() }}</span>
            </div>
            <div class="pt-chips">
                @foreach ($activeWithoutSpace as $s)
                    <button type="button" wire:click="selectSession({{ $s->id }})" class="pt-chip">
                        <span class="pt-chip-plate">{{ $s->plate }}</span>
                        <span class="pt-chip-time">{{ $s->entry_at->format('H:i') }}</span>
                        @if ($s->parking_membership_id)
                            <span class="pt-chip-badge">🎟️</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Modal Entrada --}}
    @if ($mode === 'entry')
        <div class="pt-overlay" wire:click.self="closeModal">
            <div class="pt-modal">
                <div class="pt-modal-head pt-modal-head-green">
                    <h2>
                        🚗 Entrada
                        @if ($selectedSpace)
                            · Espacio <code>{{ $selectedSpace->code }}</code>
                            @if ($selectedSpace->zone) <span class="pt-modal-zone">{{ $selectedSpace->zone }}</span> @endif
                        @else
                            · Sin espacio asignado
                        @endif
                    </h2>
                    <button type="button" wire:click="closeModal" class="pt-modal-close">✕</button>
                </div>
                <div class="pt-modal-body">
                    <div class="pt-field">
                        <label>Placa</label>
                        <input type="text" wire:model="entryForm.plate" autofocus
                            placeholder="ABC123"
                            wire:keydown.enter="registerEntry"
                            class="pt-input-plate" />
                    </div>
                    <div class="pt-field">
                        <label>Tipo de vehículo</label>
                        <div class="pt-vehicle-grid">
                            @foreach ($vehicleTypes as $vt)
                                <button type="button"
                                    wire:click="$set('entryForm.vehicle_type_id', {{ $vt->id }})"
                                    class="pt-vehicle-btn @if ((int) ($entryForm['vehicle_type_id'] ?? 0) === $vt->id) is-active @endif">
                                    <span class="pt-vehicle-icon">{{ $vt->icon ?: '🚘' }}</span>
                                    <span>{{ $vt->name }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="pt-field">
                        <label>Observaciones (opcional)</label>
                        <textarea wire:model="entryForm.notes" rows="2"
                            placeholder="Color, daños previos, etc."></textarea>
                    </div>
                </div>
                <div class="pt-modal-foot">
                    <button type="button" wire:click="closeModal" class="pt-btn pt-btn-gray">Cancelar</button>
                    <button type="button" wire:click="registerEntry" class="pt-btn pt-btn-green">
                        ✓ Registrar e imprimir ticket
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Salida --}}
    @if ($mode === 'exit' && $activeSession)
        @php
            $sess = $activeSession;
            $membership = $sess->parkingMembership;
            $minutes = $quote['minutes'] ?? 0;
            $amount = $quote['amount'] ?? 0;
            $isCovered = (float) $amount === 0.0 && $membership;
        @endphp
        <div class="pt-overlay" wire:click.self="closeModal">
            <div class="pt-modal pt-modal-lg">
                <div class="pt-modal-head pt-modal-head-blue">
                    <h2>
                        ⏏️ Salida · <code class="pt-modal-plate">{{ $sess->plate }}</code>
                        @if ($selectedSpace)
                            · Espacio <code>{{ $selectedSpace->code }}</code>
                        @endif
                    </h2>
                    <button type="button" wire:click="closeModal" class="pt-modal-close">✕</button>
                </div>
                <div class="pt-modal-body">
                    <div class="pt-session-summary">
                        <div>
                            <div class="pt-summary-label">Entrada</div>
                            <div class="pt-summary-val">{{ $sess->entry_at->format('d/m/Y H:i:s') }}</div>
                        </div>
                        <div>
                            <div class="pt-summary-label">Tiempo</div>
                            <div class="pt-summary-val">{{ $minutes }} min</div>
                        </div>
                        <div>
                            <div class="pt-summary-label">Tipo</div>
                            <div class="pt-summary-val">{{ $sess->vehicleType?->name ?? '—' }}</div>
                        </div>
                        <div class="pt-summary-amount">
                            <div class="pt-summary-label">Total</div>
                            <div class="pt-summary-amount-val">{{ $fmt($amount) }}</div>
                        </div>
                    </div>

                    @if (! empty($quote['breakdown']))
                        <div class="pt-breakdown">
                            <div class="pt-breakdown-head">Desglose</div>
                            <table>
                                @foreach ($quote['breakdown'] as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="pt-amt {{ $row['amount'] < 0 ? 'pt-amt-neg' : '' }}">{{ $fmt($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endif

                    @if ($isCovered)
                        <div class="pt-info pt-info-green">
                            🎟️ Cubierto por mensualidad/convenio. No genera factura — se cierra la sesión sin cobro.
                        </div>
                    @elseif ($amount > 0)
                        <div class="pt-payment">
                            <div class="pt-payment-head">Datos del cobro</div>
                            <div class="pt-payment-grid">
                                <div class="pt-field">
                                    <label>Medio de pago</label>
                                    <select wire:model="exitForm.payment_method">
                                        @foreach ($this->paymentMethodOptions as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="pt-field">
                                    <label>Cuenta contable</label>
                                    <select wire:model="exitForm.account_id">
                                        @foreach ($this->accountOptions as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="pt-field">
                                    <label>Tipo de factura</label>
                                    <select wire:model="exitForm.invoice_kind">
                                        <option value="pos">POS (no electrónica)</option>
                                        <option value="electronic">Electrónica DIAN</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endif

                    <details class="pt-cancel">
                        <summary>Anular sesión (errores de registro)</summary>
                        <div class="pt-cancel-body">
                            <input type="text" wire:model="exitForm.cancel_reason" placeholder="Motivo de anulación" />
                            <button type="button" wire:click="cancelSession"
                                onclick="return confirm('¿Anular esta sesión?')"
                                class="pt-btn pt-btn-red-sm">
                                Anular
                            </button>
                        </div>
                    </details>
                </div>
                <div class="pt-modal-foot pt-modal-foot-exit">
                    <button type="button" wire:click="closeModal" class="pt-btn pt-btn-gray">Cerrar</button>
                    <button type="button" wire:click="processLostTicket"
                        onclick="return confirm('¿Confirmar cobro por ticket perdido?')"
                        class="pt-btn pt-btn-amber">
                        ⚠ Ticket perdido
                    </button>
                    <button type="button" wire:click="refreshQuote" class="pt-btn pt-btn-gray">
                        🔄 Recalcular
                    </button>
                    <button type="button" wire:click="processExit" class="pt-btn pt-btn-green">
                        ✓ Cobrar y registrar salida
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Listener: abre ventana de ticket QR tras registrar entrada --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('parking-ticket-ready', (data) => {
                const id = Array.isArray(data) ? data[0]?.sessionId : data.sessionId;
                if (id) {
                    const url = '/app/parking/tickets/' + id + '/print';
                    window.open(url, '_blank', 'width=420,height=620');
                }
            });
        });
    </script>

    <style>
        .pt-bar {
            display:grid; grid-template-columns:2fr 2fr auto auto; gap:14px; align-items:end;
            background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px;
            margin-bottom:14px;
        }
        .pt-lot-picker label, .pt-scan label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; font-weight:700; margin-bottom:4px; }
        .pt-lot-tabs { display:flex; gap:6px; flex-wrap:wrap; }
        .pt-lot-tab { padding:7px 14px; border-radius:8px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-weight:600; font-size:13px; cursor:pointer; transition:.15s; }
        .pt-lot-tab:hover { background:#e2e8f0; }
        .pt-lot-tab.is-active { background:#6366f1; color:#fff; border-color:#6366f1; }

        .pt-scan { position:relative; }
        .pt-scan-input {
            width:100%; padding:9px 12px; border:2px solid #6366f1; border-radius:8px;
            font-family:ui-monospace, monospace; font-size:16px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;
            background:#eef2ff !important; color:#0f172a !important;
        }
        .pt-scan-input::placeholder { color:#94a3b8 !important; }
        .pt-scan-hint { position:absolute; right:8px; bottom:8px; font-size:10px; color:#6366f1; background:#fff; padding:1px 6px; border-radius:4px; opacity:.7; }

        .pt-kpis { display:flex; gap:6px; }
        .pt-kpi { display:flex; flex-direction:column; align-items:center; padding:7px 12px; border-radius:8px; min-width:60px; }
        .pt-kpi-num { font-size:18px; font-weight:900; }
        .pt-kpi-label { font-size:10px; text-transform:uppercase; font-weight:700; opacity:.85; }
        .pt-kpi-green { background:#dcfce7; color:#166534; }
        .pt-kpi-red { background:#fee2e2; color:#991b1b; }
        .pt-kpi-gray { background:#f1f5f9; color:#1e293b; }

        .pt-quick-entry { padding:10px 14px; background:#0f172a; color:#fff; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; }
        .pt-quick-entry:hover { background:#1e293b; }

        .pt-zone { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; margin-bottom:12px; }
        .pt-zone-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .pt-zone-head h3 { margin:0; font-size:15px; font-weight:800; color:#111827; }
        .pt-zone-count { font-size:11px; color:#6b7280; }

        .pt-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(100px, 1fr)); gap:7px; }
        .pt-tile {
            position:relative; padding:10px 6px 7px; border:0; border-radius:8px; color:#fff;
            text-align:center; min-height:80px; cursor:pointer; display:flex; flex-direction:column;
            justify-content:center; box-shadow:0 1px 2px rgba(0,0,0,.08); transition:.12s transform;
        }
        .pt-tile:hover { transform:scale(1.04); }
        .pt-tile-disabled { opacity:.55; cursor:not-allowed; }
        .pt-tile-disabled:hover { transform:none; }
        .pt-tile-code { font-family:ui-monospace, monospace; font-weight:800; font-size:13px; letter-spacing:1px; }
        .pt-tile-acc { position:absolute; top:3px; right:5px; font-size:11px; }
        .pt-tile-plate { font-family:ui-monospace, monospace; font-size:11px; opacity:.95; margin-top:2px; font-weight:700; }
        .pt-tile-time { font-size:10px; opacity:.85; }
        .pt-tile-vt { font-size:9.5px; opacity:.78; margin-top:1px; }

        .pt-empty { background:#f9fafb; border-radius:12px; padding:30px; text-align:center; color:#6b7280; }

        .pt-floating { margin-top:14px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; }
        .pt-floating-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
        .pt-floating-head h3 { margin:0; font-size:14px; font-weight:700; color:#111827; }
        .pt-floating-count { font-size:11px; color:#6b7280; }
        .pt-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .pt-chip { display:flex; align-items:center; gap:6px; padding:7px 11px; background:#fef3c7; color:#78350f; border:1px solid #f59e0b; border-radius:20px; font-size:12px; cursor:pointer; transition:.12s; }
        .pt-chip:hover { background:#fde68a; }
        .pt-chip-plate { font-family:ui-monospace, monospace; font-weight:800; letter-spacing:1px; }
        .pt-chip-time { opacity:.7; font-size:11px; }

        .pt-overlay { position:fixed; inset:0; background:rgba(15,23,42,.55); display:flex; align-items:center; justify-content:center; z-index:99; padding:14px; }
        .pt-modal { background:#fff; border-radius:14px; max-width:560px; width:100%; max-height:92vh; overflow:auto; box-shadow:0 30px 60px -20px rgba(0,0,0,.4); animation:ptUp .25s ease both; }
        .pt-modal-lg { max-width:720px; }
        @keyframes ptUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
        .pt-modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; color:#fff; }
        .pt-modal-head-green { background:#16a34a; }
        .pt-modal-head-blue { background:#4f46e5; }
        .pt-modal-head h2 { margin:0; font-size:16px; font-weight:800; }
        .pt-modal-head code { background:rgba(255,255,255,.22); padding:1px 7px; border-radius:5px; font-family:ui-monospace, monospace; }
        .pt-modal-zone { font-size:11px; opacity:.8; margin-left:6px; font-weight:500; }
        .pt-modal-plate { font-size:18px; letter-spacing:2px; }
        .pt-modal-close { background:transparent; color:#fff; border:0; font-size:22px; cursor:pointer; opacity:.85; }
        .pt-modal-close:hover { opacity:1; }

        .pt-modal-body { padding:18px; }
        .pt-field { margin-bottom:14px; }
        .pt-field label { display:block; font-size:11px; text-transform:uppercase; color:#6b7280; font-weight:700; margin-bottom:5px; }
        .pt-field input[type=text], .pt-field textarea, .pt-field select {
            width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; font-family:inherit;
            background:#fff !important; color:#0f172a !important;
        }
        .pt-field input[type=text]::placeholder, .pt-field textarea::placeholder { color:#94a3b8 !important; }
        .pt-field input[type=text]:focus, .pt-field textarea:focus, .pt-field select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
        .pt-input-plate { font-family:ui-monospace, monospace !important; font-size:24px !important; font-weight:800 !important; letter-spacing:3px; text-transform:uppercase; text-align:center; color:#0f172a !important; }

        .pt-vehicle-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(110px, 1fr)); gap:7px; }
        .pt-vehicle-btn { padding:10px 8px; background:#f1f5f9; border:2px solid transparent; border-radius:8px; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:3px; font-size:12px; font-weight:600; color:#475569; }
        .pt-vehicle-btn:hover { background:#e2e8f0; }
        .pt-vehicle-btn.is-active { background:#6366f1; color:#fff; border-color:#4f46e5; }
        .pt-vehicle-icon { font-size:22px; }

        .pt-modal-foot { display:flex; gap:7px; padding:12px 18px; background:#f8fafc; border-top:1px solid #e5e7eb; justify-content:flex-end; flex-wrap:wrap; }
        .pt-modal-foot-exit { justify-content:space-between; }

        .pt-btn { padding:9px 16px; border:0; border-radius:7px; font-weight:700; font-size:13px; cursor:pointer; }
        .pt-btn-green { background:#16a34a; color:#fff; }
        .pt-btn-green:hover { background:#15803d; }
        .pt-btn-amber { background:#f59e0b; color:#fff; }
        .pt-btn-amber:hover { background:#d97706; }
        .pt-btn-gray { background:#e2e8f0; color:#1e293b; }
        .pt-btn-gray:hover { background:#cbd5e1; }
        .pt-btn-red-sm { padding:7px 12px; background:#dc2626; color:#fff; border:0; border-radius:6px; font-weight:700; font-size:12px; cursor:pointer; }

        .pt-session-summary { display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:10px; background:#eef2ff; padding:14px 16px; border-radius:10px; margin-bottom:14px; }
        .pt-summary-label { font-size:10px; text-transform:uppercase; color:#6366f1; font-weight:700; letter-spacing:.05em; }
        .pt-summary-val { font-size:14px; font-weight:700; color:#1e1b4b; margin-top:2px; }
        .pt-summary-amount-val { font-size:24px; font-weight:900; color:#16a34a; margin-top:2px; }
        .pt-summary-amount { text-align:right; }

        .pt-breakdown { background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; margin-bottom:14px; }
        .pt-breakdown-head { font-size:11px; text-transform:uppercase; color:#6b7280; font-weight:700; margin-bottom:5px; }
        .pt-breakdown table { width:100%; font-size:13px; }
        .pt-breakdown td { padding:3px 0; }
        .pt-amt { text-align:right; font-family:ui-monospace, monospace; font-weight:600; }
        .pt-amt-neg { color:#dc2626; }

        .pt-info { padding:10px 14px; border-radius:8px; margin-bottom:14px; font-size:13px; }
        .pt-info-green { background:#dcfce7; color:#166534; border:1px solid #16a34a; }

        .pt-payment { margin-bottom:14px; }
        .pt-payment-head { font-size:11px; text-transform:uppercase; color:#6b7280; font-weight:700; margin-bottom:8px; }
        .pt-payment-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; }

        .pt-cancel summary { cursor:pointer; font-size:12px; color:#6b7280; font-weight:600; margin-bottom:6px; }
        .pt-cancel-body { display:flex; gap:7px; margin-top:6px; }
        .pt-cancel-body input { flex:1; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff !important; color:#0f172a !important; }
        .pt-cancel-body input::placeholder { color:#94a3b8 !important; }

        @media (max-width:900px) {
            .pt-bar { grid-template-columns:1fr; }
            .pt-modal-foot-exit { justify-content:flex-end; }
        }
    </style>
</x-filament-panels::page>
