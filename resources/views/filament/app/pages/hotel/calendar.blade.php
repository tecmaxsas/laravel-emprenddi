@php
    use App\Models\Hotel\Room;
    use Illuminate\Support\Carbon;

    $plata = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');

    $cal = $this->calendario;
    $resumen = $this->resumenPorDia;
    $hoy = now()->toDateString();

    // Los nombres de los días en corto, para la cabecera.
    $diaCorto = ['Mon' => 'lu', 'Tue' => 'ma', 'Wed' => 'mi', 'Thu' => 'ju',
                 'Fri' => 'vi', 'Sat' => 'sá', 'Sun' => 'do'];

    $puedeReservar = auth()->user()?->can('hotel.reservations.manage');
@endphp

<x-filament-panels::page>
    <div class="hc-bar">
        <div class="hc-field">
            <label>Hotel</label>
            <div class="hc-tabs">
                @foreach ($this->hotels as $h)
                    <button type="button" wire:click="cambiarHotel({{ $h->id }})"
                        class="hc-tab @if ($h->id === $hotelId) is-active @endif">{{ $h->name }}</button>
                @endforeach
            </div>
        </div>

        <div class="hc-field">
            <label>Desde</label>
            <input type="date" wire:model.live="desde" class="hc-input" />
        </div>

        <div class="hc-field">
            <label>Días</label>
            <select wire:model.live="dias" class="hc-input">
                <option value="7">7 días</option>
                <option value="14">14 días</option>
                <option value="21">21 días</option>
                <option value="30">30 días</option>
            </select>
        </div>

        <div class="hc-field">
            <label>Tipo</label>
            <select wire:model.live="roomTypeId" class="hc-input">
                <option value="">Todos</option>
                @foreach ($this->roomTypes as $id => $nombre)
                    <option value="{{ $id }}">{{ $nombre }}</option>
                @endforeach
            </select>
        </div>

        <div class="hc-nav">
            <button type="button" wire:click="moverDias(-{{ $dias }})" class="hc-btn hc-btn-gray">←</button>
            <button type="button" wire:click="irAHoy" class="hc-btn hc-btn-gray">Hoy</button>
            <button type="button" wire:click="moverDias({{ $dias }})" class="hc-btn hc-btn-gray">→</button>
        </div>
    </div>

    @if (! $hotelId)
        <div class="hc-empty">Selecciona un hotel.</div>
    @elseif ($cal['habitaciones']->isEmpty())
        <div class="hc-empty">
            Este hotel no tiene habitaciones. Créalas en <strong>Hotel → Habitaciones</strong>.
        </div>
    @else
        <div class="hc-wrap">
            <table class="hc-grid">
                <thead>
                    <tr>
                        <th class="hc-room-head">Habitación</th>
                        @foreach ($cal['dias'] as $dia)
                            @php $f = Carbon::parse($dia); @endphp
                            <th class="hc-day @if ($dia === $hoy) is-today @endif
                                       @if ($f->isSaturday() || $f->isSunday()) is-weekend @endif">
                                <span class="hc-day-name">{{ $diaCorto[$f->format('D')] ?? '' }}</span>
                                <span class="hc-day-num">{{ $f->format('d') }}</span>
                                <span class="hc-day-mon">{{ $f->format('m') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @php $zonaActual = null; @endphp

                    @foreach ($cal['habitaciones'] as $hab)
                        @php $zona = $hab->zone?->name ?: 'Sin zona'; @endphp

                        @if ($zona !== $zonaActual)
                            @php $zonaActual = $zona; @endphp
                            <tr class="hc-zone-row">
                                <td colspan="{{ count($cal['dias']) + 1 }}">{{ $zona }}</td>
                            </tr>
                        @endif

                        <tr>
                            <th class="hc-room">
                                <span class="hc-room-num">{{ $hab->number }}</span>
                                <span class="hc-room-type">{{ $hab->roomType?->name }}</span>
                            </th>

                            @foreach ($cal['dias'] as $dia)
                                @php
                                    $celda = $cal['ocupacion'][$hab->id][$dia] ?? null;
                                    $tipo = $celda['tipo'] ?? 'libre';
                                    $sePuedeReservar = ! $celda && $hab->sePuedeVender() && $puedeReservar;
                                @endphp

                                <td class="hc-cell hc-{{ $tipo }}
                                           @if ($dia === $hoy) is-today @endif
                                           @if ($sePuedeReservar) is-free @endif"
                                    @if ($sePuedeReservar)
                                        wire:click="reservarDesde({{ $hab->id }}, '{{ $dia }}')"
                                        title="Libre · clic para reservar"
                                    @elseif ($celda)
                                        title="{{ $celda['etiqueta'] }}{{ $celda['referencia'] ? ' · '.$celda['referencia'] : '' }}"
                                    @endif>
                                    @if ($celda && $celda['inicio'])
                                        <span class="hc-label">{{ $celda['etiqueta'] }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>

                <tfoot>
                    <tr>
                        <th class="hc-room-head">Libres</th>
                        @foreach ($cal['dias'] as $dia)
                            @php $r = $resumen[$dia] ?? ['libres' => 0, 'ocupacion' => 0]; @endphp
                            <th class="hc-total @if ($dia === $hoy) is-today @endif
                                       @if ($r['libres'] === 0) is-full @endif">
                                <span class="hc-total-num">{{ $r['libres'] }}</span>
                                <span class="hc-total-pct">{{ $r['ocupacion'] }}%</span>
                            </th>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="hc-legend">
            <span><i class="hc-estadia"></i>Alojado</span>
            <span><i class="hc-reserva"></i>Reservado</span>
            <span><i class="hc-cerrada"></i>Ya salió</span>
            <span><i class="hc-bloqueo"></i>Fuera de servicio</span>
            <span><i class="hc-libre-i"></i>Libre @if ($puedeReservar)— clic para reservar @endif</span>
        </div>
    @endif

    {{-- Reservar desde el hueco --}}
    @if ($reservaAbierta && $this->habitacionDeLaReserva)
        @php
            $hab = $this->habitacionDeLaReserva;
            $cot = $this->cotizacion;
        @endphp
        <div class="hc-overlay" wire:click.self="cerrarReserva">
            <div class="hc-modal">
                <div class="hc-modal-head">
                    <h2>📅 Reservar · Hab. <code>{{ $hab->number }}</code>
                        <span class="hc-sub">{{ $hab->roomType?->name }}</span></h2>
                    <button type="button" wire:click="cerrarReserva" class="hc-close">✕</button>
                </div>

                <div class="hc-modal-body">
                    <div class="hc-cols-2">
                        <div class="hc-field">
                            <label>Entrada</label>
                            <input type="date" wire:model.live="reservaForm.check_in_date" class="hc-input" />
                        </div>
                        <div class="hc-field">
                            <label>Salida</label>
                            <input type="date" wire:model.live="reservaForm.check_out_date" class="hc-input" />
                        </div>
                        <div class="hc-field hc-span-2">
                            <label>A nombre de *</label>
                            <input type="text" wire:model="reservaForm.guest_name" class="hc-input" autofocus />
                        </div>
                        <div class="hc-field">
                            <label>Teléfono</label>
                            <input type="text" wire:model="reservaForm.guest_phone" class="hc-input" />
                        </div>
                        <div class="hc-field">
                            <label>Origen</label>
                            <select wire:model="reservaForm.source" class="hc-input">
                                @foreach (\App\Models\Hotel\Reservation::SOURCES as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="hc-field">
                            <label>Adultos</label>
                            <input type="number" min="1" wire:model.live="reservaForm.adults" class="hc-input" />
                        </div>
                        <div class="hc-field">
                            <label>Niños</label>
                            <input type="number" min="0" wire:model="reservaForm.children" class="hc-input" />
                        </div>
                    </div>

                    {{-- La cotización noche por noche. Es lo que explica por qué
                         cinco noches de diciembre no valen lo mismo que cinco
                         de febrero. --}}
                    @if ($cot['noches'] > 0)
                        <div class="hc-quote">
                            <div class="hc-quote-head">
                                <span>{{ $cot['noches'] }} noche(s)</span>
                                <strong>{{ $plata($cot['total']) }}</strong>
                            </div>

                            @if (! empty($cot['temporadas']))
                                <div class="hc-quote-rows">
                                    @foreach ($cot['detalle'] as $d)
                                        <div>
                                            <span>{{ Carbon::parse($d['fecha'])->format('d/m') }}
                                                @if ($d['temporada'])
                                                    <em>{{ $d['temporada'] }}</em>
                                                @endif
                                            </span>
                                            <span>{{ $plata($d['tarifa']) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="hc-quote-plain">
                                    {{ $plata($cot['promedio']) }} por noche · tarifa del tipo
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="hc-field">
                        <label>Tarifa por noche</label>
                        <input type="number" min="0" step="1" wire:model="reservaForm.rate_per_night"
                            class="hc-input hc-input-big" />
                        <span class="hc-hint">
                            Si escribes una distinta a la cotizada, queda como <strong>tarifa pactada</strong>
                            y las temporadas ya no la tocan.
                        </span>
                    </div>

                    <div class="hc-field">
                        <label>Notas</label>
                        <input type="text" wire:model="reservaForm.notes" class="hc-input" />
                    </div>
                </div>

                <div class="hc-modal-foot">
                    <button type="button" wire:click="cerrarReserva" class="hc-btn hc-btn-gray">Cancelar</button>
                    <button type="button" wire:click="crearReserva" class="hc-btn hc-btn-indigo">
                        Crear reserva
                    </button>
                </div>
            </div>
        </div>
    @endif

    @include('filament.app.pages.hotel.partials.calendar-styles')
</x-filament-panels::page>
