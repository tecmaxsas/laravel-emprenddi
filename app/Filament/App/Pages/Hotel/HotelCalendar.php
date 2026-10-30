<?php

namespace App\Filament\App\Pages\Hotel;

use App\Models\Hotel\Hotel;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Services\Hotel\HotelAvailability;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelTarifas;
use App\Support\ModuleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * El calendario de disponibilidad.
 *
 * La Recepción muestra HOY, y eso cubre la mitad del trabajo. La otra mitad es
 * la pregunta que un hotel hace veinte veces al día —«¿qué tengo libre del 15
 * al 20?», «¿cómo va el puente?»— y para responderla no había pantalla: tocaba
 * empezar a crear una reserva y mirar qué salía en el desplegable.
 *
 * Esto es la vista que tiene cualquier PMS: habitaciones en filas, días en
 * columnas, cada reserva una barra. Se ve el hueco de tres noches entre dos
 * reservas, se ve la semana muerta, y desde el hueco se reserva de una vez.
 *
 * Los datos salen de `HotelAvailability::calendario()`, la misma clase que
 * decide si un check-in cabe. No es casualidad: un calendario que pintara
 * libre una noche que el check-in rechaza haría que el recepcionista dejara
 * de creerle a la pantalla, y entonces no sirve de nada.
 */
class HotelCalendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Calendario';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'hotel/calendario';

    protected static ?string $title = 'Calendario de disponibilidad';

    protected static string $view = 'filament.app.pages.hotel.calendar';

    public ?int $hotelId = null;

    public ?int $roomTypeId = null;

    public string $desde = '';

    public int $dias = 14;

    /** Modal de reserva rápida desde un hueco del calendario. */
    public bool $reservaAbierta = false;

    public ?int $reservaRoomId = null;

    public array $reservaForm = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('hotel')) {
            return false;
        }

        return (bool) auth()->user()?->can('hotel.use');
    }

    public function mount(): void
    {
        $this->hotelId = Hotel::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('id')
            ->value('id');

        // Arranca un día antes de hoy: ver la noche que acaba de pasar ayuda a
        // ubicarse, y deja a la vista quién sigue adentro esta mañana.
        $this->desde = now()->subDay()->toDateString();
    }

    /* ------------------------------------------------------------------ */
    /*  Lo que pinta la vista */
    /* ------------------------------------------------------------------ */

    public function getHotelsProperty()
    {
        return Hotel::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return array<int, string> */
    public function getRoomTypesProperty(): array
    {
        return RoomType::query()
            ->where('company_id', auth()->user()?->company_id)
            ->when($this->hotelId, fn ($q) => $q->where('hotel_id', $this->hotelId))
            ->where('active', true)
            ->orderBy('sort_order')
            ->pluck('name', 'id')
            ->all();
    }

    public function getCalendarioProperty(): array
    {
        if (! $this->hotelId) {
            return ['dias' => [], 'habitaciones' => collect(), 'ocupacion' => []];
        }

        return app(HotelAvailability::class)->calendario(
            $this->hotelId,
            $this->desde ?: now()->toDateString(),
            $this->dias,
            $this->roomTypeId,
        );
    }

    /**
     * Cuántas quedan libres cada día. Es la fila de totales del pie, y es lo
     * primero que mira quien está buscando dónde meter un grupo.
     *
     * @return array<string, array{libres: int, vendibles: int, ocupacion: int}>
     */
    public function getResumenPorDiaProperty(): array
    {
        $calendario = $this->calendario;
        $vendibles = $calendario['habitaciones']->filter(fn (Room $h) => $h->sePuedeVender())->count();

        $resumen = [];

        foreach ($calendario['dias'] as $dia) {
            $ocupadas = 0;

            foreach ($calendario['habitaciones'] as $habitacion) {
                $celda = $calendario['ocupacion'][$habitacion->id][$dia] ?? null;

                // Una estadía ya cerrada no ocupa: ese día la habitación
                // estuvo vendida, pero hoy ya se puede volver a vender.
                if ($celda && in_array($celda['tipo'], ['estadia', 'reserva'], true)) {
                    $ocupadas++;
                }
            }

            $resumen[$dia] = [
                'libres' => max(0, $vendibles - $ocupadas),
                'vendibles' => $vendibles,
                'ocupacion' => $vendibles > 0 ? (int) round($ocupadas / $vendibles * 100) : 0,
            ];
        }

        return $resumen;
    }

    /* ------------------------------------------------------------------ */
    /*  Navegación */
    /* ------------------------------------------------------------------ */

    public function moverDias(int $dias): void
    {
        $this->desde = Carbon::parse($this->desde ?: now())->addDays($dias)->toDateString();
    }

    public function irAHoy(): void
    {
        $this->desde = now()->subDay()->toDateString();
    }

    public function cambiarHotel(int $hotelId): void
    {
        $this->hotelId = $hotelId;
        $this->roomTypeId = null;
    }

    /* ------------------------------------------------------------------ */
    /*  Reservar desde un hueco */
    /* ------------------------------------------------------------------ */

    /**
     * Clic en una casilla libre: abre la reserva con esa habitación y esa
     * fecha puestas.
     *
     * Es el punto del calendario. Ver el hueco y tener que irse a otra
     * pantalla a escribir de nuevo la habitación y la fecha es la mitad del
     * trabajo hecho a mano.
     */
    public function reservarDesde(int $roomId, string $dia): void
    {
        if (! auth()->user()?->can('hotel.reservations.manage')) {
            $this->avisar('danger', 'Sin permiso para crear reservas');

            return;
        }

        $habitacion = Room::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->with('roomType')
            ->find($roomId);

        if (! $habitacion || ! $habitacion->sePuedeVender()) {
            return;
        }

        $entrada = Carbon::parse($dia)->startOfDay();

        $this->reservaRoomId = $habitacion->id;

        $this->reservaForm = [
            'check_in_date' => $entrada->toDateString(),
            'check_out_date' => $entrada->copy()->addDay()->toDateString(),
            'guest_name' => '',
            'guest_phone' => '',
            'adults' => 1,
            'children' => 0,
            'rate_per_night' => app(HotelTarifas::class)->paraNoche($habitacion->roomType, $entrada, 1),
            'source' => 'phone',
            'notes' => '',
        ];

        $this->reservaAbierta = true;
    }

    public function cerrarReserva(): void
    {
        $this->reservaAbierta = false;
        $this->reservaRoomId = null;
        $this->reservaForm = [];
    }

    public function getHabitacionDeLaReservaProperty(): ?Room
    {
        return $this->reservaRoomId
            ? Room::query()
                ->where('company_id', auth()->user()?->company_id)
                ->with('roomType')
                ->find($this->reservaRoomId)
            : null;
    }

    /** Lo que costaría la estadía con las fechas puestas, noche por noche. */
    public function getCotizacionProperty(): array
    {
        $habitacion = $this->habitacionDeLaReserva;

        if (! $habitacion || empty($this->reservaForm['check_in_date']) || empty($this->reservaForm['check_out_date'])) {
            return ['noches' => 0, 'total' => 0.0, 'detalle' => []];
        }

        try {
            return app(HotelTarifas::class)->cotizar(
                $habitacion->roomType,
                $this->reservaForm['check_in_date'],
                $this->reservaForm['check_out_date'],
                max(1, (int) ($this->reservaForm['adults'] ?? 1)),
            );
        } catch (\Throwable) {
            return ['noches' => 0, 'total' => 0.0, 'detalle' => []];
        }
    }

    public function crearReserva(): void
    {
        if (! auth()->user()?->can('hotel.reservations.manage')) {
            $this->avisar('danger', 'Sin permiso para crear reservas');

            return;
        }

        try {
            $reserva = app(HotelReservationEngine::class)->crear([
                'hotel_id' => $this->hotelId,
                'room_id' => $this->reservaRoomId,
                'check_in_date' => $this->reservaForm['check_in_date'] ?? null,
                'check_out_date' => $this->reservaForm['check_out_date'] ?? null,
                'guest_name' => $this->reservaForm['guest_name'] ?? '',
                'guest_phone' => $this->reservaForm['guest_phone'] ?? null,
                'adults' => (int) ($this->reservaForm['adults'] ?? 1),
                'children' => (int) ($this->reservaForm['children'] ?? 0),
                'rate_per_night' => $this->reservaForm['rate_per_night'] !== null
                    && $this->reservaForm['rate_per_night'] !== ''
                        ? (float) $this->reservaForm['rate_per_night']
                        : null,
                'source' => $this->reservaForm['source'] ?? 'phone',
                'notes' => $this->reservaForm['notes'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo crear la reserva', $e->getMessage());

            return;
        }

        $this->cerrarReserva();

        Notification::make()
            ->title('Reserva '.$reserva->code.' creada')
            ->body($reserva->guest_name.' · hab. '.$reserva->room?->number
                .' · '.$reserva->nights.' noche(s) · $'
                .number_format((float) $reserva->estimated_total, 0, ',', '.'))
            ->success()
            ->send();
    }

    protected function avisar(string $tipo, string $titulo, ?string $cuerpo = null): void
    {
        $n = Notification::make()->title($titulo);

        if ($cuerpo) {
            $n->body($cuerpo);
        }

        $tipo === 'danger' ? $n->danger()->persistent() : $n->warning();
        $n->send();
    }
}
