@php
    use App\Models\Hotel\Guest;
    use App\Models\Hotel\Room;
    use App\Support\ClockFormat;

    $plata = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');

    $hoteles = $this->hotels;
    $stats = $this->stats;
    $porZona = $this->roomsByZone;
    $llegadas = $this->arrivals;
    $salidas = $this->departures;
    $turno = $this->openCashSession;
    $hotel = $this->currentHotel;
    $habitacion = $this->selectedRoom;
    $estadia = $this->activeStay;
    $reserva = $this->selectedReservation;
@endphp

<x-filament-panels::page>
    {{-- ------------------------------------------------------------------
         Turno de caja. Va arriba y en rojo cuando falta porque un check-out
         sin turno abierto falla al final, con el huésped y las maletas en el
         mostrador: mejor saberlo al entrar.
    ------------------------------------------------------------------- --}}
    @if ($turno)
        <div class="hd-shift hd-shift-open">
            <div>
                💰 <strong>Turno abierto</strong>
                · {{ $turno->opened_at->format(ClockFormat::datetime()) }}
                · Base {{ $plata($turno->opening_amount) }}
                · Sede {{ $turno->location?->name ?? '—' }}
            </div>
            <button type="button" wire:click="openCloseSessionModal" class="hd-btn hd-btn-dark">
                🔒 Cerrar caja
            </button>
        </div>
    @else
        <div class="hd-shift hd-shift-closed">
            <div>
                🚨 <strong>Sin turno de caja abierto.</strong>
                Los check-out con cobro van a fallar hasta que abras caja.
            </div>
            <button type="button" wire:click="openCashModal" class="hd-btn hd-btn-danger">
                🔓 Abrir caja
            </button>
        </div>
    @endif

    {{-- Barra superior: hotel, búsqueda y KPIs --}}
    <div class="hd-bar">
        <div class="hd-hotels">
            <label>Hotel</label>
            <div class="hd-tabs">
                @forelse ($hoteles as $h)
                    <button type="button" wire:click="changeHotel({{ $h->id }})"
                        class="hd-tab @if ($h->id === $hotelId) is-active @endif">
                        {{ $h->name }}
                    </button>
                @empty
                    <span class="hd-muted">Todavía no hay hoteles configurados.</span>
                @endforelse
            </div>
        </div>

        <div class="hd-search">
            <label>Buscar habitación, huésped, reserva o folio</label>
            <input type="text" wire:model="search" wire:keydown.enter="handleSearch"
                placeholder="101 · Gómez · R-000012 · H-000045" autofocus class="hd-input" />
            <span class="hd-hint">Enter para buscar</span>
        </div>

        <div class="hd-kpis">
            <div class="hd-kpi hd-kpi-green">
                <span class="hd-kpi-num">{{ $stats['libres'] }}</span>
                <span class="hd-kpi-label">Libres</span>
            </div>
            <div class="hd-kpi hd-kpi-red">
                <span class="hd-kpi-num">{{ $stats['ocupadas'] }}</span>
                <span class="hd-kpi-label">Ocupadas</span>
            </div>
            <div class="hd-kpi hd-kpi-blue">
                <span class="hd-kpi-num">{{ $stats['aseo'] }}</span>
                <span class="hd-kpi-label">En aseo</span>
            </div>
            <div class="hd-kpi hd-kpi-gray">
                <span class="hd-kpi-num">{{ $stats['ocupacion'] }}%</span>
                <span class="hd-kpi-label">Ocupación</span>
            </div>
        </div>
    </div>

    {{-- Llegadas y salidas del día: la lista de pendientes del turno --}}
    @if ($llegadas->isNotEmpty() || $salidas->isNotEmpty())
        <div class="hd-today">
            @if ($llegadas->isNotEmpty())
                <div class="hd-today-col">
                    <div class="hd-today-head">🛬 Llegadas de hoy <span class="hd-pill">{{ $llegadas->count() }}</span></div>
                    <div class="hd-chips">
                        @foreach ($llegadas as $r)
                            <button type="button" wire:click="checkinDeReserva({{ $r->id }})" class="hd-chip">
                                <span class="hd-chip-main">{{ $r->nombreDelHuesped() }}</span>
                                <span class="hd-chip-sub">
                                    {{ $r->code }} ·
                                    {{ $r->room?->number ? 'hab. '.$r->room->number : ($r->roomType?->name ?? 'sin asignar') }}
                                    · {{ $r->nights }} noche(s)
                                </span>
                                @if (! $r->room_id)
                                    <span class="hd-chip-flag">sin habitación</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($salidas->isNotEmpty())
                <div class="hd-today-col">
                    <div class="hd-today-head">🛫 Salidas de hoy <span class="hd-pill">{{ $salidas->count() }}</span></div>
                    <div class="hd-chips">
                        @foreach ($salidas as $s)
                            <button type="button" wire:click="selectRoom({{ $s->room_id }})"
                                class="hd-chip @if ($s->seQuedoDeMas()) hd-chip-late @endif">
                                <span class="hd-chip-main">Hab. {{ $s->room?->number }}</span>
                                <span class="hd-chip-sub">
                                    {{ $s->nombreDelHuesped() }} · {{ $plata($s->total) }}
                                </span>
                                @if ($s->seQuedoDeMas())
                                    <span class="hd-chip-flag">pasó la fecha</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         El mapa. Es la pantalla: el recepcionista trabaja a golpe de clic.
    ------------------------------------------------------------------- --}}
    @if (! $hotelId)
        <div class="hd-empty">
            Selecciona un hotel para ver el mapa de habitaciones.
        </div>
    @elseif (empty($porZona))
        <div class="hd-empty">
            Este hotel no tiene habitaciones. Créalas en <strong>Hotel → Habitaciones</strong>
            (hay un botón «Crear en lote» para generar un piso completo de una vez).
        </div>
    @else
        @foreach ($porZona as $zona => $habitaciones)
            <div class="hd-zone">
                <div class="hd-zone-head">
                    <h3>{{ $zona }}</h3>
                    <span class="hd-zone-count">{{ $habitaciones->count() }} habitaciones</span>
                </div>
                <div class="hd-grid">
                    @foreach ($habitaciones as $hab)
                        @php
                            $color = Room::STATUS_COLORS[$hab->status] ?? '#6b7280';
                            $suEstadia = $hab->openStay->first();
                            $suReserva = $hab->getAttribute('reservaDeHoy');
                            $apretable = $hab->sePuedeVender() || $hab->estaOcupada();
                        @endphp
                        <button type="button"
                            @if ($apretable) wire:click="selectRoom({{ $hab->id }})" @else disabled @endif
                            class="hd-tile {{ $apretable ? '' : 'hd-tile-off' }}"
                            style="background: {{ $color }};"
                            title="{{ Room::STATUSES[$hab->status] ?? $hab->status }}{{ $hab->roomType ? ' · '.$hab->roomType->name : '' }}">
                            <div class="hd-tile-top">
                                <span class="hd-tile-num">{{ $hab->number }}</span>
                                @if ($suReserva && ! $hab->estaOcupada())
                                    <span class="hd-tile-badge" title="Reservada para hoy">📅</span>
                                @endif
                            </div>

                            @if ($suEstadia)
                                <div class="hd-tile-guest">{{ \Illuminate\Support\Str::limit($suEstadia->nombreDelHuesped(), 18) }}</div>
                                <div class="hd-tile-meta">
                                    sale {{ $suEstadia->expected_check_out->format('d/m') }}
                                    @if ($suEstadia->seQuedoDeMas()) ⚠ @endif
                                </div>
                                <div class="hd-tile-amount">{{ $plata($suEstadia->total) }}</div>
                            @elseif ($suReserva)
                                <div class="hd-tile-guest">{{ \Illuminate\Support\Str::limit($suReserva->nombreDelHuesped(), 18) }}</div>
                                <div class="hd-tile-meta">reserva {{ $suReserva->code }}</div>
                            @else
                                <div class="hd-tile-meta">{{ $hab->roomType?->name }}</div>
                                @if ($hab->status === Room::STATUS_AVAILABLE && $hab->roomType)
                                    <div class="hd-tile-amount">{{ $plata($hab->roomType->base_rate) }}</div>
                                @else
                                    <div class="hd-tile-amount">{{ Room::STATUSES[$hab->status] ?? '' }}</div>
                                @endif
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Leyenda: seis colores sin leyenda son seis colores que nadie sabe leer --}}
        <div class="hd-legend">
            @foreach (Room::STATUSES as $clave => $etiqueta)
                <span class="hd-legend-item">
                    <i style="background: {{ Room::STATUS_COLORS[$clave] ?? '#6b7280' }}"></i>{{ $etiqueta }}
                </span>
            @endforeach
        </div>
    @endif

    {{-- Panel de aseo: la habitación seleccionada que está en aseo --}}
    @if ($habitacion && $habitacion->status === Room::STATUS_CLEANING && $mode === 'idle')
        <div class="hd-overlay" wire:click.self="closeModal">
            <div class="hd-modal">
                <div class="hd-modal-head hd-head-blue">
                    <h2>🧹 Hab. {{ $habitacion->number }} en aseo</h2>
                    <button type="button" wire:click="closeModal" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <p>Cuando la habitación esté tendida, márcala como aseada y vuelve al inventario vendible.</p>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="closeModal" class="hd-btn hd-btn-gray">Cerrar</button>
                    @can('hotel.housekeeping')
                        <button type="button" wire:click="marcarAseada({{ $habitacion->id }})" class="hd-btn hd-btn-green">
                            ✓ Lista para vender
                        </button>
                    @endcan
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         CHECK-IN
    ------------------------------------------------------------------- --}}
    @if ($mode === 'checkin' && $habitacion)
        <div class="hd-overlay" wire:click.self="closeModal">
            <div class="hd-modal hd-modal-lg">
                <div class="hd-modal-head hd-head-green">
                    <h2>
                        🛏️ Check-in · Hab. <code>{{ $habitacion->number }}</code>
                        <span class="hd-head-sub">{{ $habitacion->roomType?->name }}</span>
                        @if ($reserva)
                            <span class="hd-head-sub">Reserva {{ $reserva->code }}</span>
                        @endif
                    </h2>
                    <button type="button" wire:click="closeModal" class="hd-close">✕</button>
                </div>

                <div class="hd-modal-body">
                    <div class="hd-cols-3">
                        <div class="hd-field">
                            <label>Sale el</label>
                            <input type="date" wire:model="checkinForm.expected_check_out" class="hd-input" />
                            <span class="hd-hint">La salida no se cobra como noche.</span>
                        </div>
                        <div class="hd-field">
                            <label>Adultos</label>
                            <input type="number" min="1" wire:model="checkinForm.adults" class="hd-input" />
                        </div>
                        <div class="hd-field">
                            <label>Niños</label>
                            <input type="number" min="0" wire:model="checkinForm.children" class="hd-input" />
                        </div>
                        <div class="hd-field">
                            <label>Tarifa por noche</label>
                            <input type="number" min="0" step="1" wire:model="checkinForm.rate_per_night" class="hd-input hd-input-big" />
                            <span class="hd-hint">
                                Precio al público. Del tipo: {{ $plata($habitacion->roomType?->base_rate ?? 0) }}
                            </span>
                        </div>
                        <div class="hd-field hd-span-2">
                            <label>Cliente que paga (opcional)</label>
                            @if ($checkinForm['third_party_id'] ?? null)
                                <div class="hd-picked">
                                    <span>{{ $checkinForm['third_party_label'] ?: 'Cliente seleccionado' }}</span>
                                    <button type="button" wire:click="openCustomerModal" class="hd-link">cambiar</button>
                                </div>
                            @else
                                <button type="button" wire:click="openCustomerModal" class="hd-btn hd-btn-gray hd-btn-block">
                                    Buscar o crear cliente
                                </button>
                                <span class="hd-hint">Hace falta solo si se va a recibir depósito o facturar a su nombre.</span>
                            @endif
                        </div>
                    </div>

                    {{-- Huésped titular: la tarjeta de registro hotelero --}}
                    <div class="hd-section">
                        <div class="hd-section-head">👤 Huésped titular</div>

                        @if ($checkinForm['guest_id'] ?? null)
                            <div class="hd-picked">
                                <span>{{ $checkinForm['guest_label'] ?: 'Huésped seleccionado' }}</span>
                                <button type="button" wire:click="quitarHuesped" class="hd-link">cambiar</button>
                            </div>
                        @else
                            <div class="hd-field">
                                <label>Buscar un huésped que ya se haya alojado</label>
                                <input type="text" wire:model.live.debounce.300ms="checkinForm.guest_search"
                                    placeholder="Nombre o documento (mín. 2 letras)" class="hd-input" />
                                @if ($this->guestMatches->isNotEmpty())
                                    <div class="hd-results">
                                        @foreach ($this->guestMatches as $g)
                                            <button type="button" wire:click="elegirHuesped({{ $g->id }})" class="hd-result">
                                                <strong>{{ $g->nombreCompleto() }}</strong>
                                                <span>{{ $g->documento() }}{{ $g->city ? ' · '.$g->city : '' }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="hd-cols-3">
                                <div class="hd-field">
                                    <label>Nombres *</label>
                                    <input type="text" wire:model="checkinForm.guest_first_name" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Apellidos</label>
                                    <input type="text" wire:model="checkinForm.guest_last_name" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Tipo de documento</label>
                                    <select wire:model="checkinForm.guest_document_type" class="hd-input">
                                        <option value="cc">Cédula de ciudadanía</option>
                                        <option value="ce">Cédula de extranjería</option>
                                        <option value="pa">Pasaporte</option>
                                        <option value="ti">Tarjeta de identidad</option>
                                        <option value="pep">PEP / PPT</option>
                                    </select>
                                </div>
                                <div class="hd-field">
                                    <label>Documento</label>
                                    <input type="text" wire:model="checkinForm.guest_document" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Nacionalidad</label>
                                    <input type="text" wire:model="checkinForm.guest_nationality"
                                        placeholder="Colombiana" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Teléfono</label>
                                    <input type="text" wire:model="checkinForm.guest_phone" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Procedencia</label>
                                    <input type="text" wire:model="checkinForm.guest_origin" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Destino</label>
                                    <input type="text" wire:model="checkinForm.guest_destination" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Motivo del viaje</label>
                                    <select wire:model="checkinForm.guest_purpose" class="hd-input">
                                        <option value="">—</option>
                                        @foreach (Guest::PURPOSES as $clave => $etiqueta)
                                            <option value="{{ $clave }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Exención de IVA: art. 481 ET --}}
                    <div class="hd-section">
                        <label class="hd-check">
                            <input type="checkbox" wire:model.live="checkinForm.tax_exempt" />
                            <span>Hospedaje exento de IVA (turista extranjero no residente)</span>
                        </label>
                        @if ($checkinForm['tax_exempt'] ?? false)
                            <div class="hd-field">
                                <label>Motivo / sustento</label>
                                <input type="text" wire:model="checkinForm.tax_exempt_reason"
                                    placeholder="Pasaporte, país de residencia, sello de entrada…" class="hd-input" />
                                <span class="hd-hint">
                                    Art. 481 ET. La exención hay que poder sustentarla: aplica solo al alojamiento,
                                    no a los consumos.
                                </span>
                            </div>
                        @endif
                    </div>

                    <div class="hd-field">
                        <label>Observaciones</label>
                        <textarea wire:model="checkinForm.notes" rows="2" class="hd-input"></textarea>
                    </div>
                </div>

                <div class="hd-modal-foot">
                    <button type="button" wire:click="closeModal" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="registrarCheckin" class="hd-btn hd-btn-green">
                        ✓ Alojar y abrir folio
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         FOLIO
    ------------------------------------------------------------------- --}}
    @if ($mode === 'folio' && $estadia)
        @php
            $cargos = $this->activeCharges;
            $resultados = $this->productResults;
        @endphp
        <div class="hd-overlay" wire:click.self="closeModal">
            <div class="hd-modal hd-modal-xl">
                <div class="hd-modal-head hd-head-indigo">
                    <h2>
                        🧾 Folio {{ $estadia->fullNumber() }} · Hab. <code>{{ $estadia->room?->number }}</code>
                        <span class="hd-head-sub">{{ $estadia->nombreDelHuesped() }}</span>
                    </h2>
                    <button type="button" wire:click="closeModal" class="hd-close">✕</button>
                </div>

                <div class="hd-modal-body">
                    <div class="hd-summary">
                        <div>
                            <div class="hd-sum-label">Entrada</div>
                            <div class="hd-sum-val">{{ $estadia->check_in_at->format(ClockFormat::datetime()) }}</div>
                        </div>
                        <div>
                            <div class="hd-sum-label">Sale el</div>
                            <div class="hd-sum-val">
                                {{ $estadia->expected_check_out->format('d/m/Y') }} {{ $this->horaDeSalida }}
                                @if ($estadia->seQuedoDeMas())
                                    <span class="hd-warn">pasó la fecha</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="hd-sum-label">Noches / tarifa</div>
                            <div class="hd-sum-val">{{ $estadia->nights }} × {{ $plata($estadia->rate_per_night) }}</div>
                        </div>
                        <div class="hd-sum-total">
                            <div class="hd-sum-label">Total del folio</div>
                            <div class="hd-sum-total-val">{{ $plata($estadia->total) }}</div>
                        </div>
                    </div>

                    @if ($estadia->tax_exempt)
                        <div class="hd-note">
                            🏷️ <strong>Alojamiento exento de IVA.</strong>
                            {{ $estadia->tax_exempt_reason }} — los consumos sí se gravan.
                        </div>
                    @endif

                    {{-- Renglones del folio --}}
                    <div class="hd-section">
                        <div class="hd-section-head">
                            Renglones
                            <span class="hd-muted">
                                Alojamiento {{ $plata($estadia->room_total) }}
                                · Consumos {{ $plata($estadia->consumption_total) }}
                                @if ((float) $estadia->discount_total > 0)
                                    · Rebajas −{{ $plata($estadia->discount_total) }}
                                @endif
                            </span>
                        </div>

                        <table class="hd-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Concepto</th>
                                    <th class="hd-r">Cant.</th>
                                    <th class="hd-r">Valor</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($cargos as $cargo)
                                    <tr>
                                        <td>{{ $cargo->charge_date->format('d/m') }}</td>
                                        <td><span class="hd-kind hd-kind-{{ $cargo->kind }}">{{ $cargo->etiquetaDelTipo() }}</span></td>
                                        <td>{{ $cargo->description }}</td>
                                        <td class="hd-r">{{ rtrim(rtrim(number_format((float) $cargo->quantity, 2, ',', '.'), '0'), ',') }}</td>
                                        <td class="hd-r {{ (float) $cargo->total < 0 ? 'hd-neg' : '' }}">{{ $plata($cargo->total) }}</td>
                                        <td class="hd-r">
                                            @if (! $cargo->esNoche())
                                                @can('hotel.folio.charge')
                                                    <button type="button" wire:click="quitarCargo({{ $cargo->id }})"
                                                        class="hd-x" title="Quitar del folio">✕</button>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="hd-center hd-muted">El folio todavía no tiene renglones.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Cargar consumos --}}
                    @can('hotel.folio.charge')
                        <div class="hd-section">
                            <div class="hd-section-head">🛍️ Cargar al folio</div>

                            <div class="hd-field">
                                <label>Producto o servicio del catálogo</label>
                                <input type="text" wire:model.live.debounce.300ms="chargeSearch"
                                    placeholder="Buscar (mín. 2 letras): cerveza, lavandería…" class="hd-input" />
                                @if ($resultados->isNotEmpty())
                                    <div class="hd-results">
                                        @foreach ($resultados as $p)
                                            <button type="button" wire:click="agregarProducto({{ $p->id }})" class="hd-result">
                                                <strong>{{ $p->name }}</strong>
                                                <span>{{ $p->code }} · {{ $plata($p->default_sale_price) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (strlen(trim($chargeSearch)) >= 2)
                                    <div class="hd-muted hd-pad">Sin coincidencias para «{{ $chargeSearch }}».</div>
                                @endif
                            </div>

                            <div class="hd-cols-4">
                                <div class="hd-field hd-span-2">
                                    <label>…o un servicio escrito a mano</label>
                                    <input type="text" wire:model="chargeForm.description"
                                        placeholder="Uso de salón, llamada, traslado…" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Cantidad</label>
                                    <input type="number" min="1" step="1" wire:model="chargeForm.quantity" class="hd-input" />
                                </div>
                                <div class="hd-field">
                                    <label>Valor unitario</label>
                                    <input type="number" min="0" step="1" wire:model="chargeForm.unit_price" class="hd-input" />
                                </div>
                                <div class="hd-field hd-span-2">
                                    <label>IVA</label>
                                    <select wire:model="chargeForm.tax_id" class="hd-input">
                                        <option value="">Sin IVA</option>
                                        @foreach ($this->taxOptions as $id => $etiqueta)
                                            <option value="{{ $id }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="hd-field hd-span-2 hd-bottom">
                                    <button type="button" wire:click="agregarCargoManual" class="hd-btn hd-btn-indigo hd-btn-block">
                                        + Cargar servicio
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endcan
                </div>

                <div class="hd-modal-foot hd-foot-wrap">
                    <button type="button" wire:click="closeModal" class="hd-btn hd-btn-gray">Cerrar</button>

                    <button type="button" wire:click="abrirDeposito" class="hd-btn hd-btn-gray">💵 Depósito</button>

                    <button type="button" wire:click="abrirExtension" class="hd-btn hd-btn-gray">📅 Cambiar salida</button>

                    @can('hotel.room.change')
                        <button type="button" wire:click="abrirTraslado" class="hd-btn hd-btn-gray">🔀 Trasladar</button>
                    @endcan

                    @can('hotel.folio.adjust')
                        <button type="button" wire:click="abrirAjuste" class="hd-btn hd-btn-amber">− Cortesía / ajuste</button>
                    @endcan

                    <a href="{{ route('hotel.folio.print', $estadia) }}" target="_blank" class="hd-btn hd-btn-gray">
                        🖨️ Imprimir cuenta
                    </a>

                    <a href="{{ route('hotel.registration.print', $estadia) }}" target="_blank" class="hd-btn hd-btn-gray">
                        📋 Tarjeta de registro
                    </a>

                    <button type="button" wire:click="abrirCheckout" class="hd-btn hd-btn-green hd-btn-grow">
                        ✓ Check-out y cobrar · {{ $plata($estadia->total) }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         CHECK-OUT
    ------------------------------------------------------------------- --}}
    @if ($mode === 'checkout' && $estadia)
        @php
            $vuelto = $this->changeDue;
            $faltante = $this->missingAmount;
            $pideEfectivo = $this->vueltoAplica;
            $aCredito = ($checkoutForm['modo'] ?? 'contado') === 'credito';
            $aFavor = $this->saldoAFavor;
        @endphp
        <div class="hd-overlay" wire:click.self="volverAlFolio">
            <div class="hd-modal hd-modal-lg">
                <div class="hd-modal-head hd-head-green">
                    <h2>
                        💳 Check-out · Hab. <code>{{ $estadia->room?->number }}</code>
                        <span class="hd-head-sub">{{ $estadia->nombreDelHuesped() }}</span>
                    </h2>
                    <button type="button" wire:click="volverAlFolio" class="hd-close">✕</button>
                </div>

                <div class="hd-modal-body">
                    <div class="hd-total-box">
                        <div class="hd-total-rows">
                            <div><span>Alojamiento ({{ $estadia->nights }} noche(s))</span><span>{{ $plata($estadia->room_total) }}</span></div>
                            @if ((float) $estadia->consumption_total > 0)
                                <div><span>Consumos y servicios</span><span>{{ $plata($estadia->consumption_total) }}</span></div>
                            @endif
                            @if ((float) $estadia->discount_total > 0)
                                <div class="hd-neg"><span>Rebajas y cortesías</span><span>− {{ $plata($estadia->discount_total) }}</span></div>
                            @endif
                            @if ((float) $estadia->tax_total > 0)
                                <div class="hd-muted"><span>IVA incluido</span><span>{{ $plata($estadia->tax_total) }}</span></div>
                            @endif
                        </div>
                        <div class="hd-total-big">
                            <span>TOTAL</span>
                            <span>{{ $plata($estadia->total) }}</span>
                        </div>

                        @if ($aFavor > 0.01)
                            {{-- El depósito que ya pagó. Si no se muestra aquí, el
                                 recepcionista le pide el total completo a quien ya
                                 abonó la mitad. --}}
                            <div class="hd-total-rows" style="margin-top:6px;">
                                <div><span>Tiene a favor (depósito)</span><span>− {{ $plata($aFavor) }}</span></div>
                                <div class="hd-strong-row">
                                    <span>Paga ahora</span>
                                    <span>{{ $plata(max(0, (float) $estadia->total - $aFavor)) }}</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Contado o crédito. Fiar es la excepción, así que arranca
                         en contado y el crédito hay que elegirlo a propósito. --}}
                    <div class="hd-modo">
                        <button type="button" wire:click="$set('checkoutForm.modo', 'contado')"
                            class="hd-modo-btn @if (! $aCredito) is-active @endif">
                            💵 Cobrar ahora
                        </button>
                        <button type="button" wire:click="$set('checkoutForm.modo', 'credito')"
                            class="hd-modo-btn @if ($aCredito) is-active @endif">
                            🧾 Dejar a crédito
                        </button>
                    </div>

                    @if ($aCredito)
                        <div class="hd-note">
                            La factura queda <strong>pendiente de pago</strong> y pasa a cartera con el
                            plazo que tenga pactado el cliente. Hay que asignarle el cliente o la empresa
                            que paga: a Consumidor Final no se le puede fiar, porque después nadie sabe
                            a quién cobrarle.
                        </div>
                    @endif

                    <div class="hd-cols-2">
                        <div class="hd-field" @if ($aCredito) style="display:none;" @endif>
                            <label>Forma de pago</label>
                            <select wire:model.live="checkoutForm.payment_method" class="hd-input">
                                @foreach ($this->paymentMethodOptions as $codigo => $nombre)
                                    <option value="{{ $codigo }}">{{ $nombre }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="hd-field">
                            <label>Documento</label>
                            <select wire:model="checkoutForm.invoice_kind" class="hd-input">
                                <option value="pos">Tiquete POS</option>
                                <option value="electronic">Factura electrónica (DIAN)</option>
                            </select>
                        </div>

                        @if ($pideEfectivo && ! $aCredito)
                            <div class="hd-field">
                                <label>¿Con cuánto paga?</label>
                                <input type="number" min="0" step="1" wire:model.live.debounce.400ms="checkoutForm.cash_received"
                                    class="hd-input hd-input-big" placeholder="Opcional" />
                            </div>
                            <div class="hd-field">
                                <label>Vuelto</label>
                                <div class="hd-change {{ $faltante > 0 ? 'is-short' : '' }}">
                                    @if ($faltante > 0)
                                        Falta {{ $plata($faltante) }}
                                    @else
                                        {{ $plata($vuelto) }}
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="hd-field hd-span-2">
                            <label>Cliente de la factura</label>
                            @if ($checkoutForm['third_party_id'] ?? null)
                                <div class="hd-picked">
                                    <span>{{ $checkoutForm['third_party_label'] ?: 'Cliente seleccionado' }}</span>
                                    <button type="button" wire:click="openCustomerModal" class="hd-link">cambiar</button>
                                </div>
                            @else
                                <button type="button" wire:click="openCustomerModal" class="hd-btn hd-btn-gray hd-btn-block">
                                    Buscar o crear cliente (si no, va a Consumidor Final)
                                </button>
                            @endif
                        </div>
                    </div>

                    @if (! $turno)
                        <div class="hd-note hd-note-danger">
                            🚨 No tienes turno de caja abierto: el cobro va a fallar. Abre caja primero.
                        </div>
                    @endif

                    @canany(['hotel.stay.close_without_invoice', 'hotel.stay.cancel'])
                        <div class="hd-section">
                            <div class="hd-section-head">Cerrar sin cobrar</div>
                            <div class="hd-field">
                                <label>Motivo (obligatorio)</label>
                                <input type="text" wire:model="checkoutForm.no_invoice_reason"
                                    placeholder="Cortesía, error de registro, habitación de servicio…" class="hd-input" />
                                <span class="hd-hint">
                                    Cerrar sin facturar no toca la caja, pero sí descarga el inventario de los
                                    consumos: la cerveza salió de la nevera aunque no se cobrara.
                                </span>
                            </div>
                            <div class="hd-row-btns">
                                @can('hotel.stay.close_without_invoice')
                                    <button type="button" wire:click="cerrarSinFacturar"
                                        wire:confirm="¿Cerrar este folio SIN facturar? No entra plata a la caja."
                                        class="hd-btn hd-btn-amber">
                                        Cerrar sin facturar
                                    </button>
                                @endcan
                                @can('hotel.stay.cancel')
                                    <button type="button" wire:click="anularEstadia"
                                        wire:confirm="¿Anular esta estadía? Solo se puede si no tiene consumos ni factura."
                                        class="hd-btn hd-btn-danger">
                                        Anular el check-in
                                    </button>
                                @endcan
                            </div>
                        </div>
                    @endcanany
                </div>

                <div class="hd-modal-foot">
                    <button type="button" wire:click="volverAlFolio" class="hd-btn hd-btn-gray">← Volver al folio</button>
                    <button type="button" wire:click="cobrarYCerrar"
                        class="hd-btn hd-btn-grow {{ $aCredito ? 'hd-btn-amber' : 'hd-btn-green' }}">
                        @if ($aCredito)
                            ✓ Facturar a crédito {{ $plata($estadia->total) }} y cerrar
                        @else
                            ✓ Cobrar {{ $plata(max(0, (float) $estadia->total - $aFavor)) }} y cerrar
                        @endif
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Submodales del folio --}}
    @if ($adjustOpen && $estadia)
        <div class="hd-overlay hd-overlay-top" wire:click.self="$set('adjustOpen', false)">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-amber">
                    <h2>− Cortesía / ajuste al folio</h2>
                    <button type="button" wire:click="$set('adjustOpen', false)" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <div class="hd-field">
                        <label>Concepto</label>
                        <input type="text" wire:model="adjustForm.concepto"
                            placeholder="Aire acondicionado no funcionó la 1.ª noche" class="hd-input" />
                        <span class="hd-hint">Es lo que se le explica al huésped y lo que sustenta la rebaja.</span>
                    </div>
                    <div class="hd-field">
                        <label>Valor a rebajar</label>
                        <input type="number" min="0" step="1" wire:model="adjustForm.monto" class="hd-input hd-input-big" />
                        <span class="hd-hint">
                            Entra con el IVA del alojamiento, así que la rebaja también baja el impuesto:
                            el hotel no le paga a la DIAN el IVA de una plata que no cobró.
                        </span>
                    </div>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="$set('adjustOpen', false)" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="aplicarAjuste" class="hd-btn hd-btn-amber">Aplicar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($moveOpen && $estadia)
        <div class="hd-overlay hd-overlay-top" wire:click.self="$set('moveOpen', false)">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-indigo">
                    <h2>🔀 Trasladar de habitación</h2>
                    <button type="button" wire:click="$set('moveOpen', false)" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <div class="hd-field">
                        <label>Nueva habitación</label>
                        <select wire:model="moveToRoomId" class="hd-input">
                            <option value="">Elige una…</option>
                            @foreach ($this->roomsForMove as $id => $etiqueta)
                                <option value="{{ $id }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        <span class="hd-hint">Solo las libres de hoy hasta la salida pactada.</span>
                    </div>
                    <div class="hd-field">
                        <label>Motivo</label>
                        <input type="text" wire:model="moveReason" class="hd-input" />
                    </div>
                    <p class="hd-muted">
                        El folio sigue siendo el mismo: es la cuenta de la persona, no de la habitación.
                        Las noches ya cobradas no se reescriben porque sí se durmieron allá.
                    </p>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="$set('moveOpen', false)" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="trasladar" class="hd-btn hd-btn-indigo">Trasladar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($extendOpen && $estadia)
        <div class="hd-overlay hd-overlay-top" wire:click.self="$set('extendOpen', false)">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-indigo">
                    <h2>📅 Cambiar la fecha de salida</h2>
                    <button type="button" wire:click="$set('extendOpen', false)" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <div class="hd-field">
                        <label>Nueva salida</label>
                        <input type="date" wire:model="extendTo" class="hd-input" />
                        <span class="hd-hint">
                            Se comprueba que la habitación siga libre: las noches de más pueden chocar con
                            la reserva que entra después.
                        </span>
                    </div>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="$set('extendOpen', false)" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="extender" class="hd-btn hd-btn-indigo">Guardar</button>
                </div>
            </div>
        </div>
    @endif

    @if ($depositOpen && $estadia)
        <div class="hd-overlay hd-overlay-top" wire:click.self="$set('depositOpen', false)">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-green">
                    <h2>💵 Recibir depósito</h2>
                    <button type="button" wire:click="$set('depositOpen', false)" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <div class="hd-field">
                        <label>Valor</label>
                        <input type="number" min="0" step="1" wire:model="depositAmount" class="hd-input hd-input-big" />
                    </div>
                    <div class="hd-field">
                        <label>Forma de pago</label>
                        <select wire:model="depositMethod" class="hd-input">
                            @foreach ($this->paymentMethodOptions as $codigo => $nombre)
                                <option value="{{ $codigo }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="hd-muted">
                        Entra como anticipo del cliente y queda en tu turno de caja. Al facturar el
                        check-out se cruza solo y el huésped paga la diferencia. Necesita un cliente
                        asignado a la estadía.
                    </p>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="$set('depositOpen', false)" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="recibirDeposito" class="hd-btn hd-btn-green">Recibir</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Cliente: buscar o crear sin salir de la recepción --}}
    @if ($customerModalOpen)
        <div class="hd-overlay hd-overlay-top" wire:click.self="closeCustomerModal">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-indigo">
                    <h2>Cliente</h2>
                    <button type="button" wire:click="closeCustomerModal" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <div class="hd-field">
                        <label>Buscar</label>
                        <input type="text" wire:model.live.debounce.300ms="customerSearch"
                            placeholder="Nombre o documento" class="hd-input" />
                        @if ($this->customerMatches->isNotEmpty())
                            <div class="hd-results">
                                @foreach ($this->customerMatches as $c)
                                    <button type="button" wire:click="selectCustomer({{ $c->id }})" class="hd-result">
                                        <strong>{{ $c->name }}</strong>
                                        <span>{{ $c->document_number ?: '—' }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="hd-section">
                        <div class="hd-section-head">…o crear uno nuevo</div>
                        <div class="hd-cols-2">
                            <div class="hd-field hd-span-2">
                                <label>Nombre o razón social</label>
                                <input type="text" wire:model="newCustomerName" class="hd-input" />
                            </div>
                            <div class="hd-field">
                                <label>Tipo</label>
                                <select wire:model="newCustomerDocumentType" class="hd-input">
                                    <option value="cc">Cédula</option>
                                    <option value="nit">NIT</option>
                                    <option value="ce">Cédula de extranjería</option>
                                    <option value="pa">Pasaporte</option>
                                </select>
                            </div>
                            <div class="hd-field">
                                <label>Documento</label>
                                <input type="text" wire:model="newCustomerDocument" class="hd-input" />
                            </div>
                            <div class="hd-field">
                                <label>Teléfono</label>
                                <input type="text" wire:model="newCustomerPhone" class="hd-input" />
                            </div>
                            <div class="hd-field">
                                <label>Correo</label>
                                <input type="email" wire:model="newCustomerEmail" class="hd-input" />
                            </div>
                            <div class="hd-field hd-span-2">
                                <label>Dirección</label>
                                <input type="text" wire:model="newCustomerAddress" class="hd-input" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="closeCustomerModal" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="createCustomer" class="hd-btn hd-btn-indigo">Crear y usar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Turno: abrir y cerrar caja --}}
    @if ($openCashModalOpen)
        <div class="hd-overlay hd-overlay-top" wire:click.self="closeCashModal">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-green">
                    <h2>🔓 Abrir caja</h2>
                    <button type="button" wire:click="closeCashModal" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    <div class="hd-field">
                        <label>Sede</label>
                        <select wire:model="openingLocationId" class="hd-input">
                            <option value="">Elige una…</option>
                            @foreach ($this->openingLocations as $id => $nombre)
                                <option value="{{ $id }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="hd-field">
                        <label>Base en efectivo</label>
                        <input type="number" min="0" step="1" wire:model="openingAmount" class="hd-input hd-input-big" />
                    </div>
                    <div class="hd-field">
                        <label>Notas</label>
                        <input type="text" wire:model="openingNotes" class="hd-input" />
                    </div>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="closeCashModal" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="openCashRegister" class="hd-btn hd-btn-green">Abrir</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showCloseSessionModal && $turno)
        @php $totales = $this->sessionTotals; @endphp
        <div class="hd-overlay hd-overlay-top" wire:click.self="closeCloseSessionModal">
            <div class="hd-modal hd-modal-sm">
                <div class="hd-modal-head hd-head-dark">
                    <h2>🔒 Cerrar caja</h2>
                    <button type="button" wire:click="closeCloseSessionModal" class="hd-close">✕</button>
                </div>
                <div class="hd-modal-body">
                    @if ($totales)
                        <div class="hd-total-rows">
                            <div><span>Base inicial</span><span>{{ $plata($turno->opening_amount) }}</span></div>
                            <div><span>Esperado en efectivo</span><span>{{ $plata($totales['expected_cash'] ?? 0) }}</span></div>
                        </div>
                    @endif
                    <div class="hd-field">
                        <label>Efectivo contado</label>
                        <input type="number" min="0" step="1" wire:model="closingCounted" class="hd-input hd-input-big" />
                    </div>
                    <div class="hd-field">
                        <label>Notas del cierre</label>
                        <textarea wire:model="closingNotes" rows="2" class="hd-input"></textarea>
                    </div>
                </div>
                <div class="hd-modal-foot">
                    <button type="button" wire:click="closeCloseSessionModal" class="hd-btn hd-btn-gray">Cancelar</button>
                    <button type="button" wire:click="closeCashSession" class="hd-btn hd-btn-dark">Cerrar turno</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Al cerrar un check-out se abre la impresión del tiquete, igual que en
         el POS retail: el huésped se va con su comprobante. --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('hotel-invoice-ready', (payload) => {
                const data = Array.isArray(payload) ? payload[0] : payload;
                if (! data) return;
                if (data.stayId) {
                    window.open('{{ url('/app/hotel/stays') }}/' + data.stayId + '/folio', '_blank');
                }
            });
        });
    </script>

    @include('filament.app.pages.hotel.partials.front-desk-styles')
</x-filament-panels::page>
