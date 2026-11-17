@php
    use App\Models\Billiards\BilliardCharge;
    use App\Models\Billiards\BilliardTable;
    use App\Support\ClockFormat;

    $fmt = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');

    $halls = $this->halls;
    $currentHall = $this->currentHall;
    $tablesByZone = $this->tablesByZone;
    $stats = $this->stats;
    $cashSession = $this->openCashSession;
    $sesion = $this->activeSession;
@endphp

<x-filament-panels::page>
    @include('filament.app.partials.estilos-select')
    @include('filament.app.pages.billiards.partials.estilos')

    {{-- Banner del turno. Sin caja abierta el cobro falla, y es mejor
         decirlo antes de que haya clientes esperando en el mostrador. --}}
    @if ($cashSession)
        <div style="border:1px solid #16a34a; background-color:#dcfce7; color:#166534; border-radius:8px; padding:8px 14px; font-size:12.5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
            <div>💰 <strong>Turno abierto</strong> · {{ $cashSession->opened_at->format(ClockFormat::datetime()) }} · Base {{ $fmt($cashSession->opening_amount) }} · Sede {{ $cashSession->location?->name ?? '—' }}</div>
            <button type="button" wire:click="openCloseSessionModal" class="bt-btn" style="background-color:#166534; color:#fff; padding:6px 12px; font-size:12px;">
                🔒 Cerrar caja
            </button>
        </div>
    @else
        <div style="border:1px solid #dc2626; background-color:#fee2e2; color:#991b1b; border-radius:8px; padding:10px 14px; font-size:13px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
            <div>🚨 <strong>Sin turno de caja abierto.</strong> Puedes abrir mesas, pero el cobro fallará hasta que abras caja.</div>
            <button type="button" wire:click="openCashModal" class="bt-btn" style="background-color:#991b1b; color:#fff; padding:6px 12px; font-size:12px;">
                🔓 Abrir caja
            </button>
        </div>
    @endif

    <div class="bt-bar">
        <div>
            <label>Sala</label>
            <div class="bt-hall-tabs">
                @forelse ($halls as $hall)
                    <button type="button" wire:click="changeHall({{ $hall->id }})"
                        class="bt-hall-tab @if ($hall->id === $hallId) is-active @endif">
                        {{ $hall->name }}
                    </button>
                @empty
                    <span style="font-size:13px; color:#991b1b;">
                        No hay salas. Créala en <strong>Billares → Salas</strong>.
                    </span>
                @endforelse
            </div>
        </div>

        <div class="bt-kpis">
            <div class="bt-kpi bt-kpi-green">
                <span class="bt-kpi-num">{{ $stats['free'] }}</span>
                <span class="bt-kpi-label">Libres</span>
            </div>
            <div class="bt-kpi bt-kpi-red">
                <span class="bt-kpi-num">{{ $stats['occupied'] }}</span>
                <span class="bt-kpi-label">Ocupadas</span>
            </div>
            <div class="bt-kpi bt-kpi-gray">
                <span class="bt-kpi-num">{{ $stats['total'] }}</span>
                <span class="bt-kpi-label">Mesas</span>
            </div>
        </div>

        {{-- `wire:poll` y no un setInterval propio: el reloj de cada tile lo
             lleva el navegador, pero el total con los consumos que otro
             mesero acaba de cargar solo lo sabe el servidor. Medio minuto es
             suficiente para que el mapa no mienta y poco para doce mesas. --}}
        <div wire:poll.30s style="font-size:11px; color:#94a3b8; align-self:end; padding-bottom:6px;">
            se refresca solo
        </div>
    </div>

    {{-- El mapa del salón --}}
    @if (! $hallId)
        <div class="bt-empty">Selecciona una sala para ver el mapa.</div>
    @elseif (empty($tablesByZone))
        <div class="bt-empty">
            Esta sala no tiene mesas configuradas. Créalas en <strong>Billares → Mesas</strong>.
        </div>
    @else
        @foreach ($tablesByZone as $zona => $mesas)
            <div class="bt-zone">
                <div class="bt-zone-head">
                    <h3>{{ $zona }}</h3>
                    <span class="bt-zone-count">{{ count($mesas) }} mesa{{ count($mesas) === 1 ? '' : 's' }}</span>
                </div>
                <div class="bt-grid">
                    @foreach ($mesas as $mesa)
                        @php
                            $resumen = $this->resumenDeMesa($mesa);
                            $pausada = $resumen && ! $resumen['running'];
                            $color = $pausada
                                ? '#b45309'
                                : (BilliardTable::STATUS_COLORS[$mesa->status] ?? '#6b7280');
                            $operable = in_array($mesa->status, [BilliardTable::STATUS_FREE, BilliardTable::STATUS_OCCUPIED], true);
                        @endphp
                        <button type="button"
                            @if ($operable) wire:click="selectTable({{ $mesa->id }})" @else disabled @endif
                            class="bt-tile {{ $operable ? '' : 'bt-tile-disabled' }}"
                            style="background-color:{{ $color }};"
                            title="{{ $mesa->etiqueta() }} · {{ BilliardTable::STATUSES[$mesa->status] ?? $mesa->status }}">
                            <span class="bt-tile-code">
                                <span>{{ $mesa->code }}</span>
                                <span>{{ $mesa->tableType?->icon }}</span>
                            </span>
                            <span class="bt-tile-type">{{ $mesa->tableType?->name ?? 'Sin tipo' }}</span>

                            @if ($resumen)
                                @if ($resumen['session']->customer_label)
                                    <span class="bt-tile-label">{{ $resumen['session']->customer_label }}</span>
                                @endif
                                @if ($pausada)
                                    <span class="bt-tile-paused">⏸ en pausa</span>
                                @endif
                                {{-- El contador lo lleva el navegador: repintar
                                     doce mesas cada segundo desde el servidor
                                     serían doce consultas por segundo para
                                     mover un número. --}}
                                <span class="bt-tile-clock"
                                    x-data="{
                                        segundos: {{ $resumen['minutes'] * 60 }},
                                        corriendo: {{ $resumen['running'] ? 'true' : 'false' }},
                                        get texto() {
                                            const h = Math.floor(this.segundos / 3600);
                                            const m = Math.floor((this.segundos % 3600) / 60);
                                            return h > 0 ? `${h}h ${String(m).padStart(2,'0')}m` : `${m} min`;
                                        },
                                    }"
                                    x-init="setInterval(() => { if (corriendo) segundos += 1 }, 1000)"
                                    x-text="texto">{{ $this->enHoras($resumen['minutes']) }}</span>
                                <span class="bt-tile-total">{{ $fmt($resumen['total']) }}</span>
                            @else
                                <span class="bt-tile-clock" style="opacity:.75;">
                                    {{ BilliardTable::STATUSES[$mesa->status] ?? $mesa->status }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    {{-- ============================================================ --}}
    {{--  Abrir mesa                                                   --}}
    {{-- ============================================================ --}}
    @if ($mode === 'open' && $this->selectedTable)
        @php $mesa = $this->selectedTable; @endphp
        <div class="bt-overlay" wire:click.self="closeModal">
            <div class="bt-modal">
                <div class="bt-modal-head bt-modal-head-green">
                    <div>
                        <h2>🎱 Abrir {{ $mesa->etiqueta() }}</h2>
                        <div class="bt-modal-sub">
                            {{ $mesa->tableType?->name ?? 'Sin tipo' }}@if ($mesa->zone) · {{ $mesa->zone }} @endif
                        </div>
                    </div>
                    <button type="button" wire:click="closeModal" class="bt-modal-close">✕</button>
                </div>
                <div class="bt-modal-body">
                    <div class="bt-grid-2">
                        <div class="bt-field">
                            <label>Jugadores</label>
                            <input type="number" min="1" max="20" step="1" inputmode="numeric"
                                wire:model="openForm.players" onwheel="this.blur()" />
                        </div>
                        <div class="bt-field">
                            <label>¿Cómo los llamamos?</label>
                            <input type="text" wire:model="openForm.customer_label"
                                placeholder="Don Jorge, los del torneo…" />
                        </div>
                    </div>
                    <div class="bt-field">
                        <label>Notas</label>
                        <textarea rows="2" wire:model="openForm.notes" placeholder="Opcional"></textarea>
                    </div>
                    <p style="font-size:12px; color:#64748b; margin:0;">
                        El reloj arranca al confirmar.
                    </p>
                </div>
                <div class="bt-modal-foot">
                    <button type="button" wire:click="closeModal" class="bt-btn bt-btn-ghost">Cancelar</button>
                    <button type="button" wire:click="abrirMesa" class="bt-btn bt-btn-success">
                        ▶ Abrir y arrancar el reloj
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{--  La cuenta de la mesa                                         --}}
    {{-- ============================================================ --}}
    @if ($mode === 'account' && $sesion)
        @php
            $quote = $this->quote;
            $cargos = $this->charges;
            $resultados = $this->productSearchResults;
            $pausada = $sesion->estaPausada();
        @endphp
        <div class="bt-overlay" wire:click.self="closeModal">
            <div class="bt-modal bt-modal-lg">
                <div class="bt-modal-head bt-modal-head-blue">
                    <div>
                        <h2>{{ $sesion->table?->etiqueta() }} · {{ $sesion->customer_label ?: 'Sin nombre' }}</h2>
                        <div class="bt-modal-sub">
                            {{ $sesion->tableType?->name ?? 'Sin tipo' }} ·
                            {{ $sesion->players }} jugador{{ $sesion->players === 1 ? '' : 'es' }} ·
                            desde {{ $sesion->started_at->format(ClockFormat::time()) }}
                            @if (! $sesion->rate) · <strong>⚠ sin tarifa configurada</strong> @endif
                        </div>
                    </div>
                    <button type="button" wire:click="closeModal" class="bt-modal-close">✕</button>
                </div>

                <div class="bt-modal-body">
                    <div class="bt-clock-box {{ $pausada ? 'bt-clock-paused' : '' }}">
                        <div>
                            <div class="bt-clock-cap">{{ $pausada ? 'Reloj detenido' : 'Tiempo jugado' }}</div>
                            <div class="bt-clock-big"
                                x-data="{
                                    segundos: {{ $sesion->minutosJugados() * 60 }},
                                    corriendo: {{ $pausada ? 'false' : 'true' }},
                                    get texto() {
                                        const h = Math.floor(this.segundos / 3600);
                                        const m = Math.floor((this.segundos % 3600) / 60);
                                        const s = this.segundos % 60;
                                        return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
                                    },
                                }"
                                x-init="setInterval(() => { if (corriendo) segundos += 1 }, 1000)"
                                x-text="texto">—</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="bt-clock-cap">Tiempo a cobrar</div>
                            <div class="bt-clock-big">{{ $fmt($quote['amount'] ?? 0) }}</div>
                        </div>
                    </div>

                    @if (! empty($quote['breakdown']))
                        <div class="bt-breakdown">
                            @foreach ($quote['breakdown'] as $linea)
                                <div>
                                    <span>{{ $linea['label'] }}</span>
                                    <span>{{ $fmt($linea['amount']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="bt-actions-row">
                        @if ($pausada)
                            <button type="button" wire:click="reanudar" class="bt-btn bt-btn-success">▶ Reanudar</button>
                        @else
                            <button type="button" wire:click="pausar" class="bt-btn bt-btn-warning">⏸ Pausar reloj</button>
                        @endif
                        <button type="button" wire:click="abrirTraslado" class="bt-btn bt-btn-ghost">↔ Trasladar de mesa</button>
                        <button type="button" wire:click="abrirCobro" class="bt-btn bt-btn-primary">💵 Cerrar y cobrar</button>
                    </div>

                    @unless ($pausada)
                        <div class="bt-field">
                            <label>Motivo de la pausa (opcional)</label>
                            <input type="text" wire:model="pauseReason" placeholder="Se fueron a comer" />
                        </div>
                    @endunless

                    {{-- Consumos --}}
                    <div class="bt-field">
                        <label>Cargar consumo a la mesa</label>
                        <input type="text" wire:model.live.debounce.250ms="consumoSearch"
                            placeholder="Buscar producto (mín. 2 letras)…" />
                        @if ($resultados->count() > 0)
                            <div class="bt-search-results">
                                @foreach ($resultados as $p)
                                    <button type="button" wire:click="agregarConsumo({{ $p->id }})" class="bt-search-row">
                                        <span><strong>{{ $p->name }}</strong><span class="bt-search-code">{{ $p->code }}</span></span>
                                        <span>{{ $fmt($p->default_sale_price) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif (mb_strlen(trim($consumoSearch)) >= 2)
                            <div class="bt-search-empty">Sin coincidencias para "{{ $consumoSearch }}"</div>
                        @endif
                    </div>

                    @if ($cargos->isNotEmpty())
                        <div class="bt-lines">
                            @foreach ($cargos as $cargo)
                                <div class="bt-line {{ $cargo->kind === BilliardCharge::KIND_TIME ? 'bt-line-time' : '' }} {{ $cargo->kind === BilliardCharge::KIND_ADJUSTMENT ? 'bt-line-adj' : '' }}">
                                    <div class="bt-line-desc">
                                        <strong>{{ $cargo->description }}</strong>
                                        <span class="bt-line-meta">
                                            {{ $cargo->etiquetaDelTipo() }} ·
                                            {{ rtrim(rtrim(number_format((float) $cargo->quantity, 2, ',', '.'), '0'), ',') }}
                                            × {{ $fmt($cargo->unit_price) }}
                                        </span>
                                    </div>
                                    <div class="bt-line-amount">{{ $fmt($cargo->total) }}</div>
                                    @unless ($cargo->loPoneElSistema())
                                        <button type="button" wire:click="repetirConsumo({{ $cargo->id }})"
                                            class="bt-line-btn" title="Otra igual">＋</button>
                                        <button type="button" wire:click="quitarCargo({{ $cargo->id }})"
                                            class="bt-line-btn" title="Quitar">✕</button>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Cortesías. El permiso se verifica otra vez en el
                         servidor: esconder el botón no es una barrera. --}}
                    @can('billiards.adjust')
                        <div class="bt-grid-2">
                            <div class="bt-field">
                                <label>Cortesía / ajuste</label>
                                <input type="text" wire:model="ajusteConcepto" placeholder="Por qué se rebaja" />
                            </div>
                            <div class="bt-field">
                                <label>Valor a rebajar</label>
                                <div style="display:flex; gap:6px;">
                                    <input type="number" min="0" step="1" inputmode="numeric"
                                        wire:model="ajusteMonto" onwheel="this.blur()" />
                                    <button type="button" wire:click="agregarAjuste" class="bt-btn bt-btn-ghost">Aplicar</button>
                                </div>
                            </div>
                        </div>
                    @endcan

                    <div class="bt-totals">
                        <div class="bt-total-row">
                            <span>Consumos</span><span>{{ $fmt($sesion->consumption_total) }}</span>
                        </div>
                        <div class="bt-total-row">
                            <span>Tiempo liquidado</span><span>{{ $fmt($sesion->time_total) }}</span>
                        </div>
                        <div class="bt-total-row">
                            <span>Tiempo corriendo</span><span>{{ $fmt($quote['amount'] ?? 0) }}</span>
                        </div>
                        @if ((float) $sesion->discount_total > 0)
                            <div class="bt-total-row" style="color:#b45309;">
                                <span>Cortesías</span><span>− {{ $fmt($sesion->discount_total) }}</span>
                            </div>
                        @endif
                        <div class="bt-total-grand">
                            <span>Va debiendo</span><span>{{ $fmt($this->grandTotal) }}</span>
                        </div>
                    </div>
                </div>

                <div class="bt-modal-foot">
                    @can('billiards.cancel')
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" wire:model="cancelReason" placeholder="Motivo para anular"
                                style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12.5px; background-color:#fff; color:#0f172a;" />
                            <button type="button" wire:click="anular" class="bt-btn bt-btn-danger">Anular cuenta</button>
                        </div>
                    @else
                        <span></span>
                    @endcan
                    <button type="button" wire:click="closeModal" class="bt-btn bt-btn-ghost">Cerrar ventana</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{--  Traslado de mesa                                             --}}
    {{-- ============================================================ --}}
    @if ($mode === 'transfer' && $sesion)
        <div class="bt-overlay" wire:click.self="closeModal">
            <div class="bt-modal">
                <div class="bt-modal-head bt-modal-head-amber">
                    <div>
                        <h2>↔ Trasladar {{ $sesion->table?->etiqueta() }}</h2>
                        <div class="bt-modal-sub">El tiempo jugado hasta ahora se cobra a la tarifa de esta mesa.</div>
                    </div>
                    <button type="button" wire:click="closeModal" class="bt-modal-close">✕</button>
                </div>
                <div class="bt-modal-body">
                    @php $libres = $this->freeTables; @endphp
                    @if ($libres->isEmpty())
                        <div class="bt-search-empty">No hay mesas libres en esta sala.</div>
                    @else
                        <div class="bt-field">
                            <label>Mesa de destino</label>
                            <select wire:model="transferToTableId">
                                <option value="">Elegir…</option>
                                @foreach ($libres as $libre)
                                    <option value="{{ $libre->id }}">
                                        {{ $libre->etiqueta() }} — {{ $libre->tableType?->name ?? 'Sin tipo' }}{{ $libre->zone ? ' ('.$libre->zone.')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="bt-modal-foot">
                    <button type="button" wire:click="$set('mode', 'account')" class="bt-btn bt-btn-ghost">Volver</button>
                    <button type="button" wire:click="trasladar" class="bt-btn bt-btn-warning">Trasladar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{--  Cobro                                                        --}}
    {{-- ============================================================ --}}
    @if ($mode === 'close' && $sesion)
        @php
            $quote = $this->quote;
            $total = $this->grandTotal;
            $recibido = (float) ($cobroForm['cash_received'] ?? 0);
            $aplicaVuelto = \App\Support\Vuelto::aplica($cobroForm['payment_method'] ?? null);
            $vuelto = \App\Support\Vuelto::calcular($recibido, $total);
            $falta = \App\Support\Vuelto::faltante($recibido, $total);
        @endphp
        <div class="bt-overlay" wire:click.self="closeModal">
            <div class="bt-modal bt-modal-lg">
                <div class="bt-modal-head bt-modal-head-blue">
                    <div>
                        <h2>💵 Cobrar {{ $sesion->table?->etiqueta() }}</h2>
                        <div class="bt-modal-sub">{{ $sesion->customer_label ?: 'Sin nombre' }} · {{ $this->enHoras($sesion->minutosJugados()) }} jugados</div>
                    </div>
                    <button type="button" wire:click="closeModal" class="bt-modal-close">✕</button>
                </div>

                <div class="bt-modal-body">
                    <div class="bt-totals" style="margin-bottom:14px;">
                        <div class="bt-total-row"><span>Consumos</span><span>{{ $fmt($sesion->consumption_total) }}</span></div>
                        <div class="bt-total-row"><span>Tiempo</span><span>{{ $fmt(($sesion->time_total) + ($quote['amount'] ?? 0)) }}</span></div>
                        @if ((float) $sesion->discount_total > 0)
                            <div class="bt-total-row" style="color:#b45309;"><span>Cortesías</span><span>− {{ $fmt($sesion->discount_total) }}</span></div>
                        @endif
                        <div class="bt-total-grand"><span>Total</span><span>{{ $fmt($total) }}</span></div>
                    </div>

                    <div class="bt-grid-2">
                        <div class="bt-field">
                            <label>Medio de pago</label>
                            <select wire:model.live="cobroForm.payment_method">
                                @foreach ($this->paymentMethodOptions as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="bt-field">
                            <label>Cuenta contable</label>
                            <select wire:model="cobroForm.account_id">
                                @foreach ($this->accountOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- El vuelto solo en efectivo: de una transferencia nadie
                         devuelve plata. En un billar casi siempre se paga con
                         billete grande, así que es donde más se usa. --}}
                    @if ($aplicaVuelto)
                        <div class="bt-grid-2">
                            <div class="bt-field">
                                <label>Con cuánto paga</label>
                                <input type="number" step="1" min="0" inputmode="numeric"
                                    wire:model.live.debounce.300ms="cobroForm.cash_received"
                                    onwheel="this.blur()" placeholder="Opcional" />
                            </div>
                            <div class="bt-field">
                                <label>Cambio</label>
                                <div style="padding:8px 0; font-size:18px; font-weight:800;">
                                    @if ($recibido <= 0)
                                        <span style="color:#9ca3af; font-size:14px; font-weight:500;">—</span>
                                    @elseif ($falta > 0)
                                        <span style="color:rgb(220,38,38); font-size:15px;">Faltan {{ $fmt($falta) }}</span>
                                    @else
                                        <span style="color:rgb(5,150,105);">{{ $fmt($vuelto) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="bt-grid-2">
                        <div class="bt-field">
                            <label>Cliente</label>
                            <button type="button" wire:click="openCustomerModal"
                                style="width:100%; text-align:left; padding:8px 10px; border-radius:7px; cursor:pointer; border:1px solid #cbd5e1; background-color:#fff; color:#111827;">
                                <div style="font-size:13px; font-weight:700;">
                                    {{ $cobroForm['third_party_label'] ?? 'Consumidor Final' }}
                                </div>
                                <div style="font-size:11px; color:#6b7280;">Toca para buscar o crear</div>
                            </button>
                        </div>
                        <div class="bt-field">
                            <label>Tipo de factura</label>
                            <select wire:model="cobroForm.invoice_kind">
                                <option value="pos">POS (no electrónica)</option>
                                <option value="electronic">Electrónica DIAN</option>
                            </select>
                        </div>
                    </div>

                    <div class="bt-grid-2">
                        <div class="bt-field">
                            <label>Partidas jugadas</label>
                            <input type="number" min="0" step="1" inputmode="numeric"
                                wire:model="cobroForm.games" onwheel="this.blur()" />
                        </div>
                        <div class="bt-field">
                            <label>Cobrar otro valor por el tiempo</label>
                            <input type="number" min="0" step="1" inputmode="numeric"
                                wire:model="cobroForm.amount_override" onwheel="this.blur()"
                                placeholder="Dejar vacío = lo que calcula la tarifa" />
                        </div>
                    </div>
                    <p style="font-size:11.5px; color:#64748b; margin:0;">
                        Si digitas otro valor, en el desglose queda lo que la tarifa habría cobrado:
                        la diferencia se ve en los reportes en vez de perderse.
                    </p>
                </div>

                <div class="bt-modal-foot">
                    <button type="button" wire:click="$set('mode', 'account')" class="bt-btn bt-btn-ghost">Volver a la cuenta</button>
                    <button type="button" wire:click="cobrar" class="bt-btn bt-btn-success">
                        ✅ Cerrar mesa y facturar
                    </button>
                </div>
            </div>
        </div>
    @endif

    @include('filament.app.pages.billiards.partials.modales-caja')
</x-filament-panels::page>
