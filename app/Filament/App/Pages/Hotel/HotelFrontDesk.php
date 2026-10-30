<?php

namespace App\Filament\App\Pages\Hotel;

use App\Models\CashRegisterSession;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Services\Cash\CashSessionCloser;
use App\Services\Cash\CashSessionSummary;
use App\Services\Hotel\HotelAvailability;
use App\Services\Hotel\HotelBillingEngine;
use App\Services\Hotel\HotelFolio;
use App\Services\Hotel\HotelProductProvisioner;
use App\Services\Hotel\HotelStayEngine;
use App\Services\Sales\CustomerAdvanceService;
use App\Services\Sales\QuickCustomer;
use App\Support\ClockFormat;
use App\Support\HotelSettings;
use App\Support\ModuleGate;
use App\Support\PaymentAccountResolver;
use App\Support\PaymentMethodOptions;
use App\Support\Vuelto;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Recepción: el POS del hotel.
 *
 * Una sola pantalla con el mapa de habitaciones. El recepcionista trabaja a
 * golpe de clic sobre el tile:
 *
 *   libre     → check-in (de una reserva que llega hoy, o de mostrador)
 *   ocupada   → el folio: cargar consumos, trasladar, extender, cobrar
 *   en aseo   → marcar aseada y devolverla al inventario
 *
 * El estado de la modal se controla con $mode, igual que el terminal de
 * parqueadero:
 *
 *   idle      sin modal
 *   checkin   capturando el alojamiento de la habitación elegida
 *   folio     la cuenta del huésped que está dentro
 *   checkout  cobro y cierre
 *
 * Esta pantalla NO decide nada: la disponibilidad la resuelve
 * HotelAvailability, el folio HotelFolio, el ciclo HotelStayEngine y la
 * factura HotelBillingEngine. Lo único que vive aquí es qué se le muestra al
 * recepcionista y en qué orden.
 */
class HotelFrontDesk extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Recepción';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'hotel';

    protected static ?string $title = 'Recepción';

    protected static string $view = 'filament.app.pages.hotel.front-desk';

    public ?int $hotelId = null;

    public string $search = '';

    /** idle | checkin | folio | checkout */
    public string $mode = 'idle';

    public ?int $selectedRoomId = null;

    public ?int $activeStayId = null;

    public ?int $selectedReservationId = null;

    public array $checkinForm = [];

    public array $checkoutForm = [];

    public array $chargeForm = [];

    public array $adjustForm = [];

    public string $chargeSearch = '';

    public bool $adjustOpen = false;

    public bool $moveOpen = false;

    public ?int $moveToRoomId = null;

    public string $moveReason = '';

    public bool $extendOpen = false;

    public ?string $extendTo = null;

    public bool $depositOpen = false;

    public ?float $depositAmount = null;

    public string $depositMethod = 'cash';

    /** Lo que devuelve el cierre: para disparar la impresión de la cuenta. */
    public ?int $printStayId = null;

    /* ------------------------------------------------------------------ */
    /*  Acceso */
    /* ------------------------------------------------------------------ */

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

        $this->resetCheckinForm();
        $this->resetCheckoutForm();
        $this->resetChargeForm();
    }

    /* ------------------------------------------------------------------ */
    /*  Lo que pinta la vista */
    /* ------------------------------------------------------------------ */

    public function getHotelsProperty(): Collection
    {
        return Hotel::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'default_invoice_kind', 'check_out_time']);
    }

    public function getCurrentHotelProperty(): ?Hotel
    {
        return $this->hotelId
            ? Hotel::query()->where('company_id', auth()->user()?->company_id)->find($this->hotelId)
            : null;
    }

    /**
     * El mapa, agrupado por zona.
     *
     * Cada habitación llega con su estadía abierta y con la reserva que llega
     * hoy, si la hay: el tile necesita mostrar quién está dentro y cuánto
     * debe sin que la vista tenga que consultar nada por su cuenta.
     *
     * @return array<string, Collection<int, Room>>
     */
    public function getRoomsByZoneProperty(): array
    {
        if (! $this->hotelId) {
            return [];
        }

        $habitaciones = Room::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->where('active', true)
            ->with([
                'zone:id,name,display_order',
                'roomType:id,name,code,base_rate,max_adults,max_children',
                'openStay' => fn ($q) => $q->with('primaryGuest:id,first_name,last_name'),
            ])
            ->get();

        // Las reservas que llegan hoy, para marcar los tiles apartados. Una
        // sola consulta en vez de una por habitación.
        $llegadas = $this->llegadasDeHoy()->keyBy('room_id');

        foreach ($habitaciones as $habitacion) {
            $habitacion->setAttribute('reservaDeHoy', $llegadas->get($habitacion->id));
        }

        return $habitaciones
            ->sort(function (Room $a, Room $b) {
                $ordenA = (int) ($a->zone?->display_order ?? 9999);
                $ordenB = (int) ($b->zone?->display_order ?? 9999);

                if ($ordenA !== $ordenB) {
                    return $ordenA <=> $ordenB;
                }

                // Orden natural: la 2 antes de la 10.
                return strnatcasecmp((string) $a->number, (string) $b->number);
            })
            ->groupBy(fn (Room $habitacion) => $habitacion->zone?->name ?: 'Sin zona')
            ->all();
    }

    /** Reservas que llegan hoy y todavía no entraron. */
    public function getArrivalsProperty(): Collection
    {
        return $this->llegadasDeHoy();
    }

    /** Estadías que debían salir hoy o antes. */
    public function getDeparturesProperty(): Collection
    {
        if (! $this->hotelId) {
            return collect();
        }

        return Stay::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->abiertas()
            ->whereDate('expected_check_out', '<=', now()->toDateString())
            ->with(['room:id,number', 'primaryGuest:id,first_name,last_name'])
            ->orderBy('expected_check_out')
            ->get();
    }

    public function getStatsProperty(): array
    {
        if (! $this->hotelId) {
            return ['total' => 0, 'libres' => 0, 'ocupadas' => 0, 'aseo' => 0, 'fuera' => 0, 'ocupacion' => 0];
        }

        $conteo = Room::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->where('active', true)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $total = array_sum($conteo);
        $libres = (int) ($conteo[Room::STATUS_AVAILABLE] ?? 0);
        $ocupadas = (int) ($conteo[Room::STATUS_OCCUPIED] ?? 0);
        $aseo = (int) ($conteo[Room::STATUS_CLEANING] ?? 0);

        return [
            'total' => $total,
            'libres' => $libres,
            'ocupadas' => $ocupadas,
            'aseo' => $aseo,
            'fuera' => $total - $libres - $ocupadas - $aseo,
            // Ocupación sobre el inventario total, que es como se mide en
            // hotelería: las habitaciones en aseo también estaban vendidas.
            'ocupacion' => $total > 0 ? (int) round($ocupadas / $total * 100) : 0,
        ];
    }

    public function getSelectedRoomProperty(): ?Room
    {
        return $this->selectedRoomId
            ? Room::query()
                ->where('company_id', auth()->user()?->company_id)
                ->with(['roomType', 'zone'])
                ->find($this->selectedRoomId)
            : null;
    }

    public function getActiveStayProperty(): ?Stay
    {
        return $this->activeStayId
            ? Stay::query()
                ->where('company_id', auth()->user()?->company_id)
                ->with(['room:id,number', 'roomType:id,name', 'primaryGuest', 'customer:id,name,document_number', 'guests'])
                ->find($this->activeStayId)
            : null;
    }

    /** @return Collection<int, StayCharge> */
    public function getActiveChargesProperty(): Collection
    {
        if (! $this->activeStayId) {
            return collect();
        }

        return StayCharge::query()
            ->where('stay_id', $this->activeStayId)
            ->orderBy('charge_date')
            ->orderBy('id')
            ->get();
    }

    public function getSelectedReservationProperty(): ?Reservation
    {
        return $this->selectedReservationId
            ? Reservation::query()
                ->where('company_id', auth()->user()?->company_id)
                ->with(['roomType:id,name,base_rate,max_adults,max_children'])
                ->find($this->selectedReservationId)
            : null;
    }

    /** Habitaciones libres para un traslado, desde hoy hasta la salida pactada. */
    public function getRoomsForMoveProperty(): array
    {
        $estadia = $this->activeStay;

        if (! $estadia) {
            return [];
        }

        $desde = now()->startOfDay();
        $hasta = $estadia->expected_check_out->copy()->startOfDay();

        if (! $hasta->gt($desde)) {
            $hasta = $desde->copy()->addDay();
        }

        return app(HotelAvailability::class)
            ->habitacionesLibres(
                (int) $estadia->hotel_id,
                $desde,
                $hasta,
                ignorarEstadiaId: (int) $estadia->id,
            )
            ->mapWithKeys(fn (Room $h) => [$h->id => $h->number.' — '.($h->roomType?->name ?? '')])
            ->all();
    }

    public function getPaymentMethodOptionsProperty(): array
    {
        return PaymentMethodOptions::para();
    }

    public function getProductResultsProperty(): Collection
    {
        $q = trim($this->chargeSearch);

        if (strlen($q) < 2) {
            return collect();
        }

        $companyId = (int) (auth()->user()?->company_id ?? 0);

        if (! $companyId) {
            return collect();
        }

        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_sellable', true)
            ->where('active', true)
            // El producto de alojamiento no se carga como consumo: las noches
            // las pone el folio, no el recepcionista a mano.
            ->where('code', '!=', HotelProductProvisioner::CODE)
            ->where(fn ($builder) => $builder
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('code', 'ilike', "%{$q}%"))
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'code', 'name', 'default_sale_price', 'default_sale_tax_id', 'type']);
    }

    /** Lo que hay que devolverle al huésped, si paga en efectivo. */
    public function getChangeDueProperty(): float
    {
        $estadia = $this->activeStay;

        if (! $estadia) {
            return 0.0;
        }

        return Vuelto::calcular(
            (float) ($this->checkoutForm['cash_received'] ?? 0),
            (float) $estadia->total,
        );
    }

    public function getMissingAmountProperty(): float
    {
        $estadia = $this->activeStay;

        if (! $estadia || ! ($this->checkoutForm['cash_received'] ?? null)) {
            return 0.0;
        }

        return Vuelto::faltante(
            (float) $this->checkoutForm['cash_received'],
            (float) $estadia->total,
        );
    }

    /**
     * Cuánto tiene el huésped a favor.
     *
     * Es el depósito, que al facturar se cruza solo. Se muestra antes de
     * cobrar porque si no, el recepcionista le pide el total completo a quien
     * ya pagó la mitad por anticipado.
     */
    public function getSaldoAFavorProperty(): float
    {
        $estadia = $this->activeStay;
        $cliente = $estadia?->customer;

        if (! $cliente) {
            return 0.0;
        }

        return app(CustomerAdvanceService::class)->availableBalance($cliente);
    }

    public function getVueltoAplicaProperty(): bool
    {
        return Vuelto::aplica($this->checkoutForm['payment_method'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /*  Navegación del mapa */
    /* ------------------------------------------------------------------ */

    public function changeHotel(int $hotelId): void
    {
        $this->hotelId = $hotelId;
        $this->closeModal();
    }

    /**
     * Clic en un tile. Resuelve a qué modal llevar según el estado.
     */
    public function selectRoom(int $roomId): void
    {
        $habitacion = Room::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->find($roomId);

        if (! $habitacion) {
            return;
        }

        if ($habitacion->estaOcupada()) {
            $estadia = Stay::query()
                ->where('company_id', auth()->user()?->company_id)
                ->where('room_id', $habitacion->id)
                ->abiertas()
                ->latest('check_in_at')
                ->first();

            if (! $estadia) {
                $this->avisar(
                    'warning',
                    'Habitación en estado inconsistente',
                    'La '.$habitacion->number.' está marcada como ocupada pero no tiene estadía abierta. '
                    .'Corrige el estado desde Hotel → Habitaciones.',
                );

                return;
            }

            $this->abrirFolio((int) $estadia->id);

            return;
        }

        if ($habitacion->status === Room::STATUS_CLEANING) {
            $this->selectedRoomId = $habitacion->id;
            $this->avisar(
                'info',
                'Habitación '.$habitacion->number.' en aseo',
                'Márcala como aseada cuando esté lista para volver a venderse.',
            );

            return;
        }

        if (! $habitacion->sePuedeVender()) {
            $this->avisar(
                'info',
                'Habitación no disponible',
                'Estado actual: '.(Room::STATUSES[$habitacion->status] ?? $habitacion->status),
            );

            return;
        }

        $this->abrirCheckin((int) $habitacion->id);
    }

    public function abrirCheckin(int $roomId, ?int $reservationId = null): void
    {
        $this->selectedRoomId = $roomId;
        $this->activeStayId = null;
        $this->selectedReservationId = $reservationId;
        $this->mode = 'checkin';
        $this->resetCheckinForm();
    }

    /**
     * Check-in de una llegada de hoy. Si la reserva no tiene habitación
     * asignada, se abre el check-in para que el recepcionista elija una libre.
     */
    public function checkinDeReserva(int $reservationId): void
    {
        $reserva = Reservation::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($reservationId);

        if (! $reserva) {
            return;
        }

        if (! $reserva->room_id) {
            $this->avisar(
                'info',
                'Esta reserva no tiene habitación asignada',
                'Elige una habitación libre del mapa para alojar a '.$reserva->nombreDelHuesped().'.',
            );
            $this->selectedReservationId = $reservationId;

            return;
        }

        $this->abrirCheckin((int) $reserva->room_id, $reservationId);
    }

    protected function abrirFolio(int $stayId): void
    {
        $estadia = Stay::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($stayId);

        if (! $estadia) {
            return;
        }

        // Las noches que falten se cargan al abrir el folio: el recepcionista
        // tiene que ver lo que el huésped debe hoy, no lo que debía al entrar.
        app(HotelFolio::class)->cargarNochesPendientes($estadia);

        $this->hotelId = (int) $estadia->hotel_id;
        $this->activeStayId = (int) $estadia->id;
        $this->selectedRoomId = (int) $estadia->room_id;
        $this->selectedReservationId = null;
        $this->mode = 'folio';
        $this->resetChargeForm();
        $this->cerrarSubmodales();
    }

    public function closeModal(): void
    {
        $this->mode = 'idle';
        $this->selectedRoomId = null;
        $this->activeStayId = null;
        $this->selectedReservationId = null;
        $this->chargeSearch = '';
        $this->cerrarSubmodales();
        $this->resetCheckinForm();
        $this->resetCheckoutForm();
        $this->resetChargeForm();
    }

    /**
     * Busca por número de habitación, huésped, código de reserva o folio.
     *
     * Es el campo al que el recepcionista va cuando suena el teléfono y le
     * preguntan por «el señor Gómez» o por «la reserva R-12».
     */
    public function handleSearch(): void
    {
        $texto = trim($this->search);
        $this->search = '';

        if ($texto === '') {
            return;
        }

        $companyId = auth()->user()?->company_id;

        // Folio: H-000123 o 123
        if (preg_match('/^H-?0*(\d+)$/i', $texto, $m)) {
            $estadia = Stay::query()
                ->where('company_id', $companyId)
                ->where('number', (int) $m[1])
                ->abiertas()
                ->first();

            if ($estadia) {
                $this->abrirFolio((int) $estadia->id);

                return;
            }
        }

        // Reserva: R-000012
        if (preg_match('/^R-?0*(\d+)$/i', $texto, $m)) {
            $reserva = Reservation::query()
                ->where('company_id', $companyId)
                ->where('id', (int) $m[1])
                ->first();

            if ($reserva && $reserva->estaViva()) {
                $this->checkinDeReserva((int) $reserva->id);

                return;
            }
        }

        // Número de habitación
        $habitacion = Room::query()
            ->where('company_id', $companyId)
            ->where('hotel_id', $this->hotelId)
            ->where('number', $texto)
            ->first();

        if ($habitacion) {
            $this->selectRoom((int) $habitacion->id);

            return;
        }

        // Nombre del huésped, entre los que están alojados
        $estadia = Stay::query()
            ->where('company_id', $companyId)
            ->abiertas()
            ->where(fn ($q) => $q
                ->whereHas('primaryGuest', fn ($g) => $g
                    ->where('first_name', 'ilike', "%{$texto}%")
                    ->orWhere('last_name', 'ilike', "%{$texto}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$texto}%")))
            ->latest('check_in_at')
            ->first();

        if ($estadia) {
            $this->abrirFolio((int) $estadia->id);

            return;
        }

        $this->avisar('warning', 'Sin resultados', "No encontré nada para «{$texto}».");
    }

    /* ------------------------------------------------------------------ */
    /*  Check-in */
    /* ------------------------------------------------------------------ */

    public function registrarCheckin(): void
    {
        if ($this->mode !== 'checkin') {
            return;
        }

        $datos = [
            'hotel_id' => $this->hotelId,
            'room_id' => $this->selectedRoomId,
            'expected_check_out' => $this->checkinForm['expected_check_out'] ?? null,
            'adults' => (int) ($this->checkinForm['adults'] ?? 1),
            'children' => (int) ($this->checkinForm['children'] ?? 0),
            'rate_per_night' => $this->checkinForm['rate_per_night'] !== null && $this->checkinForm['rate_per_night'] !== ''
                ? (float) $this->checkinForm['rate_per_night']
                : null,
            'third_party_id' => $this->checkinForm['third_party_id'] ?? null,
            'tax_exempt' => (bool) ($this->checkinForm['tax_exempt'] ?? false),
            'tax_exempt_reason' => $this->checkinForm['tax_exempt_reason'] ?? null,
            'notes' => $this->checkinForm['notes'] ?? null,
        ];

        try {
            $huesped = $this->resolverHuesped();
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo registrar el huésped', $e->getMessage());

            return;
        }

        if ($huesped) {
            $datos['primary_guest_id'] = $huesped->id;
            $datos['guest_ids'] = [$huesped->id];
        }

        try {
            $motor = app(HotelStayEngine::class);
            $reserva = $this->selectedReservation;

            $estadia = $reserva
                ? $motor->checkInDeReserva($reserva, $datos)
                : $motor->checkIn($datos);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo hacer el check-in', $e->getMessage());

            return;
        }

        Notification::make()
            ->title('✓ Check-in · hab. '.$estadia->room?->number)
            ->body('Folio '.$estadia->fullNumber().' · '.$estadia->nights.' noche(s) · sale el '
                .$estadia->expected_check_out->format('d/m/Y'))
            ->success()
            ->send();

        // Queda en el folio: lo normal después de alojar es cargar algo o
        // recibir el depósito, no volver al mapa.
        $this->abrirFolio((int) $estadia->id);
    }

    /**
     * El huésped del check-in: uno ya registrado o uno nuevo.
     *
     * Se crea aquí y no en una pantalla aparte porque el huésped está en el
     * mostrador: mandar al recepcionista a Hotel → Huéspedes y volver es
     * perder la habitación y el cliente.
     */
    protected function resolverHuesped(): ?Guest
    {
        if (! empty($this->checkinForm['guest_id'])) {
            return Guest::query()
                ->where('company_id', auth()->user()?->company_id)
                ->find($this->checkinForm['guest_id']);
        }

        $nombre = trim((string) ($this->checkinForm['guest_first_name'] ?? ''));

        if ($nombre === '') {
            return null;
        }

        $documento = trim((string) ($this->checkinForm['guest_document'] ?? ''));
        $tipo = $this->checkinForm['guest_document_type'] ?? 'cc';

        if ($documento === '' && HotelSettings::exigeDocumento()) {
            throw new \RuntimeException(
                'El titular necesita documento: es lo que pide la tarjeta de registro hotelero. '
                .'Si este hotel no lo exige, apágalo en Configuración → Hotel.'
            );
        }

        // Si ya estuvo alojado, es la misma persona: se reusa su ficha en vez
        // de duplicarla, que es lo que llena el registro de homónimos.
        if ($documento !== '') {
            $existente = Guest::query()
                ->where('company_id', auth()->user()?->company_id)
                ->where('document_type', $tipo)
                ->where('document_number', $documento)
                ->first();

            if ($existente) {
                return $existente;
            }
        }

        return Guest::create([
            'company_id' => auth()->user()?->company_id,
            'document_type' => $tipo,
            'document_number' => $documento ?: null,
            'first_name' => $nombre,
            'last_name' => trim((string) ($this->checkinForm['guest_last_name'] ?? '')) ?: null,
            'nationality' => trim((string) ($this->checkinForm['guest_nationality'] ?? '')) ?: null,
            'phone' => trim((string) ($this->checkinForm['guest_phone'] ?? '')) ?: null,
            'email' => trim((string) ($this->checkinForm['guest_email'] ?? '')) ?: null,
            'origin' => trim((string) ($this->checkinForm['guest_origin'] ?? '')) ?: null,
            'destination' => trim((string) ($this->checkinForm['guest_destination'] ?? '')) ?: null,
            'travel_purpose' => $this->checkinForm['guest_purpose'] ?? null,
        ]);
    }

    /** @return Collection<int, Guest> */
    public function getGuestMatchesProperty(): Collection
    {
        $q = trim((string) ($this->checkinForm['guest_search'] ?? ''));

        if (strlen($q) < 2) {
            return collect();
        }

        return Guest::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where(fn ($builder) => $builder
                ->where('first_name', 'ilike', "%{$q}%")
                ->orWhere('last_name', 'ilike', "%{$q}%")
                ->orWhere('document_number', 'ilike', "%{$q}%"))
            ->orderBy('last_name')
            ->limit(8)
            ->get();
    }

    public function elegirHuesped(int $guestId): void
    {
        $huesped = Guest::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($guestId);

        if (! $huesped) {
            return;
        }

        $this->checkinForm['guest_id'] = $huesped->id;
        $this->checkinForm['guest_label'] = $huesped->nombreCompleto().' · '.$huesped->documento();
        $this->checkinForm['guest_search'] = '';
    }

    public function quitarHuesped(): void
    {
        $this->checkinForm['guest_id'] = null;
        $this->checkinForm['guest_label'] = null;
    }

    /* ------------------------------------------------------------------ */
    /*  Folio: consumos y ajustes */
    /* ------------------------------------------------------------------ */

    public function agregarProducto(int $productId): void
    {
        if (! auth()->user()?->can('hotel.folio.charge')) {
            $this->avisar('danger', 'Sin permiso para cargar consumos al folio');

            return;
        }

        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        $producto = Product::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($productId);

        if (! $producto) {
            return;
        }

        try {
            app(HotelFolio::class)->agregarConsumo($estadia, [
                'product_id' => $producto->id,
                'description' => $producto->name,
                'quantity' => max(1, (float) ($this->chargeForm['quantity'] ?? 1)),
                'unit_price' => (float) $producto->default_sale_price,
                'tax_id' => $producto->default_sale_tax_id,
            ]);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo cargar el consumo', $e->getMessage());

            return;
        }

        $this->chargeSearch = '';
        $this->resetChargeForm();

        Notification::make()->title('Cargado al folio')->body($producto->name)->success()->send();
    }

    /** Un cargo escrito a mano: lavandería, llamada, uso de salón. */
    public function agregarCargoManual(): void
    {
        if (! auth()->user()?->can('hotel.folio.charge')) {
            $this->avisar('danger', 'Sin permiso para cargar consumos al folio');

            return;
        }

        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        try {
            app(HotelFolio::class)->agregarConsumo($estadia, [
                'kind' => StayCharge::KIND_SERVICE,
                'description' => (string) ($this->chargeForm['description'] ?? ''),
                'quantity' => max(1, (float) ($this->chargeForm['quantity'] ?? 1)),
                'unit_price' => (float) ($this->chargeForm['unit_price'] ?? 0),
                'tax_id' => $this->chargeForm['tax_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo cargar el servicio', $e->getMessage());

            return;
        }

        $this->resetChargeForm();
        Notification::make()->title('Servicio cargado al folio')->success()->send();
    }

    public function quitarCargo(int $chargeId): void
    {
        if (! auth()->user()?->can('hotel.folio.charge')) {
            $this->avisar('danger', 'Sin permiso para modificar el folio');

            return;
        }

        $cargo = StayCharge::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('stay_id', $this->activeStayId)
            ->find($chargeId);

        if (! $cargo) {
            return;
        }

        // Una noche no se quita a mano: la liquidación del check-out decide
        // cuántas se cobran, y quitarla aquí la volvería a cargar sola.
        if ($cargo->esNoche()) {
            $this->avisar(
                'warning',
                'Las noches no se quitan del folio',
                'Si la estadía se acortó, cambia la fecha de salida: la liquidación ajusta las noches sola.',
            );

            return;
        }

        try {
            app(HotelFolio::class)->quitar($cargo);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo quitar el cargo', $e->getMessage());

            return;
        }

        Notification::make()->title('Cargo quitado del folio')->success()->send();
    }

    public function abrirAjuste(): void
    {
        if (! auth()->user()?->can('hotel.folio.adjust')) {
            $this->avisar(
                'danger',
                'Sin permiso para rebajar el folio',
                'Las cortesías y los ajustes los autoriza un administrador.',
            );

            return;
        }

        $this->adjustForm = ['concepto' => '', 'monto' => null];
        $this->adjustOpen = true;
    }

    public function aplicarAjuste(): void
    {
        if (! auth()->user()?->can('hotel.folio.adjust')) {
            $this->avisar('danger', 'Sin permiso para rebajar el folio');

            return;
        }

        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        try {
            app(HotelFolio::class)->agregarAjuste(
                $estadia,
                (string) ($this->adjustForm['concepto'] ?? ''),
                (float) ($this->adjustForm['monto'] ?? 0),
            );
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo aplicar el ajuste', $e->getMessage());

            return;
        }

        $this->adjustOpen = false;
        Notification::make()->title('Ajuste aplicado al folio')->success()->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Traslado y extensión */
    /* ------------------------------------------------------------------ */

    public function abrirTraslado(): void
    {
        if (! auth()->user()?->can('hotel.room.change')) {
            $this->avisar('danger', 'Sin permiso para trasladar huéspedes');

            return;
        }

        $this->moveToRoomId = null;
        $this->moveReason = '';
        $this->moveOpen = true;
    }

    public function trasladar(): void
    {
        if (! auth()->user()?->can('hotel.room.change')) {
            $this->avisar('danger', 'Sin permiso para trasladar huéspedes');

            return;
        }

        $estadia = $this->activeStay;

        if (! $estadia || ! $this->moveToRoomId) {
            return;
        }

        try {
            $estadia = app(HotelStayEngine::class)
                ->cambiarHabitacion($estadia, (int) $this->moveToRoomId, $this->moveReason);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo trasladar', $e->getMessage());

            return;
        }

        $this->moveOpen = false;
        $this->selectedRoomId = (int) $estadia->room_id;

        Notification::make()
            ->title('Huésped trasladado a la hab. '.$estadia->room?->number)
            ->body('El folio sigue siendo el mismo: es la cuenta de la persona, no de la habitación.')
            ->success()
            ->send();
    }

    public function abrirExtension(): void
    {
        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        $this->extendTo = $estadia->expected_check_out->copy()->addDay()->toDateString();
        $this->extendOpen = true;
    }

    public function extender(): void
    {
        $estadia = $this->activeStay;

        if (! $estadia || ! $this->extendTo) {
            return;
        }

        try {
            $estadia = app(HotelStayEngine::class)->cambiarFechaDeSalida($estadia, $this->extendTo);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo cambiar la fecha de salida', $e->getMessage());

            return;
        }

        $this->extendOpen = false;

        Notification::make()
            ->title('Salida movida al '.$estadia->expected_check_out->format('d/m/Y'))
            ->body($estadia->nights.' noche(s) en total.')
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Depósito */
    /* ------------------------------------------------------------------ */

    public function abrirDeposito(): void
    {
        $this->depositAmount = null;
        $this->depositMethod = 'cash';
        $this->depositOpen = true;
    }

    public function recibirDeposito(): void
    {
        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        try {
            app(HotelBillingEngine::class)->registrarDeposito($estadia, [
                'amount' => (float) ($this->depositAmount ?? 0),
                'payment_method' => $this->depositMethod,
                'account_id' => PaymentAccountResolver::forMethod($this->depositMethod),
            ]);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo recibir el depósito', $e->getMessage());

            return;
        }

        $this->depositOpen = false;

        Notification::make()
            ->title('Depósito recibido')
            ->body('Queda como saldo a favor del cliente y se cruza solo al facturar el check-out.')
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Check-out */
    /* ------------------------------------------------------------------ */

    /**
     * Abre el cobro dejando el folio ya liquidado.
     *
     * La cuenta que se le enseña al huésped tiene que ser la misma que se le
     * cobra: liquidar al abrir —y no al dar «cobrar»— evita que la cifra
     * cambie entre que la mira y la paga.
     */
    public function abrirCheckout(): void
    {
        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        try {
            $resumen = app(HotelStayEngine::class)->liquidar($estadia);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo liquidar la estadía', $e->getMessage());

            return;
        }

        $this->resetCheckoutForm();
        $this->checkoutForm['third_party_id'] = $estadia->third_party_id;
        $this->checkoutForm['third_party_label'] = $estadia->customer?->name;
        $this->mode = 'checkout';

        if ($resumen['noche_extra']) {
            $this->avisar(
                'warning',
                'Salida tardía: se cobró una noche más',
                'La hora pactada es '.$resumen['hora_pactada'].'. Si no corresponde, aplica un ajuste al folio.',
            );
        }
    }

    public function volverAlFolio(): void
    {
        $this->mode = 'folio';
    }

    public function cobrarYCerrar(): void
    {
        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        $metodo = $this->checkoutForm['payment_method'] ?? 'cash';
        $cuenta = (int) ($this->checkoutForm['account_id'] ?? 0)
            ?: (int) PaymentAccountResolver::forMethod($metodo);

        $aCredito = ($this->checkoutForm['modo'] ?? 'contado') === 'credito';

        try {
            $factura = app(HotelBillingEngine::class)->issueForStay($estadia, [
                'invoice_kind' => $this->checkoutForm['invoice_kind'] ?? 'pos',
                'payment_method' => $metodo,
                'account_id' => $cuenta,
                'a_credito' => $aCredito,
                'paid_amount' => (float) $estadia->total,
                'cash_received' => $this->checkoutForm['cash_received'] ?? null,
                'third_party_id' => $this->checkoutForm['third_party_id'] ?? null,
                'reference' => 'Folio '.$estadia->fullNumber(),
            ]);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo cerrar el check-out', $e->getMessage());

            return;
        }

        $stayId = (int) $estadia->id;
        $this->closeModal();

        $saldo = round((float) $factura->total - (float) $factura->paid_amount, 2);

        Notification::make()
            ->title('✓ Check-out · hab. '.$estadia->room?->number)
            ->body('Factura '.$factura->fullNumber().' · $'.number_format((float) $factura->total, 0, ',', '.')
                .($saldo > 0.01
                    ? ' · QUEDA A CRÉDITO: $'.number_format($saldo, 0, ',', '.')
                        .($factura->due_date ? ', vence el '.$factura->due_date->format('d/m/Y') : '')
                    : ''))
            ->success()
            ->persistent($saldo > 0.01)
            ->send();

        $this->printStayId = $stayId;
        $this->dispatch('hotel-invoice-ready', invoiceId: $factura->id, stayId: $stayId);
    }

    public function cerrarSinFacturar(): void
    {
        if (! auth()->user()?->can('hotel.stay.close_without_invoice')) {
            $this->avisar(
                'danger',
                'Sin permiso para cerrar sin facturar',
                'Dejar de cobrar una estadía lo autoriza un administrador.',
            );

            return;
        }

        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        $motivo = trim((string) ($this->checkoutForm['no_invoice_reason'] ?? ''));

        if ($motivo === '') {
            $this->avisar('warning', 'Falta el motivo', 'Escribe por qué esta estadía no se cobra.');

            return;
        }

        try {
            app(HotelStayEngine::class)->cerrarSinFacturar($estadia, $motivo);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo cerrar', $e->getMessage());

            return;
        }

        $this->closeModal();

        Notification::make()
            ->title('Estadía cerrada sin factura')
            ->body($motivo.' — no entró plata, pero el inventario de los consumos sí se descargó.')
            ->warning()
            ->send();
    }

    public function anularEstadia(): void
    {
        if (! auth()->user()?->can('hotel.stay.cancel')) {
            $this->avisar('danger', 'Sin permiso para anular estadías');

            return;
        }

        $estadia = $this->activeStay;

        if (! $estadia) {
            return;
        }

        $motivo = trim((string) ($this->checkoutForm['no_invoice_reason'] ?? ''));

        if ($motivo === '') {
            $this->avisar('warning', 'Falta el motivo', 'Escribe por qué se anula el check-in.');

            return;
        }

        try {
            app(HotelStayEngine::class)->anular($estadia, $motivo);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo anular', $e->getMessage());

            return;
        }

        $this->closeModal();
        Notification::make()->title('Estadía anulada')->body($motivo)->warning()->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Camarería */
    /* ------------------------------------------------------------------ */

    public function marcarAseada(int $roomId): void
    {
        if (! auth()->user()?->can('hotel.housekeeping')) {
            $this->avisar('danger', 'Sin permiso para cambiar el estado de las habitaciones');

            return;
        }

        $habitacion = Room::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->find($roomId);

        if (! $habitacion) {
            return;
        }

        try {
            app(HotelStayEngine::class)->marcarAseada($habitacion);
        } catch (\Throwable $e) {
            $this->avisar('danger', 'No se pudo marcar', $e->getMessage());

            return;
        }

        $this->selectedRoomId = null;
        Notification::make()->title('Hab. '.$habitacion->number.' lista')->success()->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Cliente del cobro */
    /* ------------------------------------------------------------------ */

    public string $customerSearch = '';

    public bool $customerModalOpen = false;

    public string $newCustomerName = '';

    public string $newCustomerDocumentType = 'cc';

    public string $newCustomerDocument = '';

    public string $newCustomerEmail = '';

    public string $newCustomerPhone = '';

    public string $newCustomerAddress = '';

    /** @return Collection<int, ThirdParty> */
    public function getCustomerMatchesProperty(): Collection
    {
        return app(QuickCustomer::class)->search(
            (int) auth()->user()?->company_id,
            $this->customerSearch,
        );
    }

    public function openCustomerModal(): void
    {
        $this->customerModalOpen = true;
    }

    public function closeCustomerModal(): void
    {
        $this->customerModalOpen = false;
        $this->customerSearch = '';
        $this->newCustomerName = '';
        $this->newCustomerDocumentType = 'cc';
        $this->newCustomerDocument = '';
        $this->newCustomerEmail = '';
        $this->newCustomerPhone = '';
        $this->newCustomerAddress = '';
    }

    public function selectCustomer(int $thirdPartyId): void
    {
        $cliente = ThirdParty::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($thirdPartyId);

        if (! $cliente) {
            Notification::make()->danger()->title('Ese cliente ya no existe')->send();

            return;
        }

        if ($this->mode === 'checkin') {
            $this->checkinForm['third_party_id'] = $cliente->id;
            $this->checkinForm['third_party_label'] = $cliente->name;
        } else {
            $this->checkoutForm['third_party_id'] = $cliente->id;
            $this->checkoutForm['third_party_label'] = $cliente->name;
        }

        // El cliente también se guarda en la estadía: el depósito y la
        // factura lo necesitan, y volverlo a buscar en el check-out es trabajo
        // repetido.
        if ($estadia = $this->activeStay) {
            $estadia->update(['third_party_id' => $cliente->id]);
        }

        $this->closeCustomerModal();
    }

    public function createCustomer(): void
    {
        try {
            $resultado = app(QuickCustomer::class)->create(
                (int) auth()->user()?->company_id,
                [
                    'name' => $this->newCustomerName,
                    'document_type' => $this->newCustomerDocumentType,
                    'document_number' => $this->newCustomerDocument,
                    'email' => $this->newCustomerEmail,
                    'phone' => $this->newCustomerPhone,
                    'address' => $this->newCustomerAddress,
                ],
            );
        } catch (\RuntimeException $e) {
            Notification::make()->danger()
                ->title('No se pudo crear el cliente')->body($e->getMessage())->send();

            return;
        }

        $cliente = $resultado['customer'];
        $this->selectCustomer((int) $cliente->id);

        $resultado['existed']
            ? Notification::make()->warning()
                ->title('Ese documento ya estaba registrado')
                ->body(trim("Se seleccionó {$cliente->name}. ".($resultado['note'] ?? '')))->send()
            : Notification::make()->success()->title("Cliente {$cliente->name} creado")->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Turno de caja */
    /* ------------------------------------------------------------------ */

    public bool $showCloseSessionModal = false;

    public float $closingCounted = 0.0;

    public string $closingNotes = '';

    public bool $openCashModalOpen = false;

    public ?int $openingLocationId = null;

    public ?float $openingAmount = 0.0;

    public string $openingNotes = '';

    public function getOpenCashSessionProperty(): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('cashier_user_id', auth()->id())
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();
    }

    public function getSessionTotalsProperty(): ?array
    {
        $sesion = $this->openCashSession;

        return $sesion ? app(CashSessionSummary::class)->compute($sesion) : null;
    }

    /** @return array<int, string> */
    public function getOpeningLocationsProperty(): array
    {
        return Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function openCashModal(): void
    {
        if ($this->openCashSession) {
            Notification::make()->warning()->title('Ya tienes un turno abierto')->send();

            return;
        }

        $this->openingLocationId = Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->value('id');

        $this->openingAmount = 0.0;
        $this->openingNotes = '';
        $this->openCashModalOpen = true;
    }

    public function closeCashModal(): void
    {
        $this->openCashModalOpen = false;
    }

    public function openCashRegister(): void
    {
        if ($this->openCashSession) {
            Notification::make()->warning()->title('Ya tienes un turno abierto')->send();

            return;
        }

        if (! $this->openingLocationId) {
            Notification::make()->danger()->title('Selecciona la sede')->send();

            return;
        }

        $base = max(0, (float) ($this->openingAmount ?? 0));

        $sesion = CashRegisterSession::create([
            'company_id' => auth()->user()->company_id,
            'location_id' => $this->openingLocationId,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => $base,
            'opening_notes' => trim($this->openingNotes) ?: null,
        ]);

        $this->openCashModalOpen = false;
        $this->openingNotes = '';

        Notification::make()->success()
            ->title('Caja abierta')
            ->body('Sede '.$sesion->location?->name.'. Base: $'.number_format($base, 0, ',', '.'))
            ->send();
    }

    public function openCloseSessionModal(): void
    {
        if (! $this->openCashSession) {
            Notification::make()->title('No hay caja abierta')->danger()->send();

            return;
        }

        $this->closingCounted = 0.0;
        $this->closingNotes = '';
        $this->showCloseSessionModal = true;
    }

    public function closeCloseSessionModal(): void
    {
        $this->showCloseSessionModal = false;
    }

    public function closeCashSession(): void
    {
        $sesion = $this->openCashSession;

        if (! $sesion) {
            Notification::make()->title('No hay caja abierta')->danger()->send();

            return;
        }

        if (! auth()->user()->can('pos.cash_close')) {
            Notification::make()->title('Sin permiso para cerrar caja')->danger()->send();

            return;
        }

        try {
            $resultado = app(CashSessionCloser::class)->close(
                $sesion,
                (float) ($this->closingCounted ?? 0),
                $this->closingNotes,
            );
        } catch (\Throwable $e) {
            Notification::make()->title('No se pudo cerrar la caja')
                ->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->showCloseSessionModal = false;
        $this->closingCounted = 0.0;
        $this->closingNotes = '';

        unset($this->openCashSession, $this->sessionTotals);

        $empresa = auth()->user()?->company;
        $cierreCiego = (bool) data_get($empresa?->settings, 'pos.blind_cash_close', false)
            && ! auth()->user()->hasAnyRole(['admin', 'manager']);

        if ($cierreCiego) {
            Notification::make()->title('Caja cerrada')->success()->send();

            return;
        }

        Notification::make()
            ->title('Caja cerrada')
            ->body(sprintf(
                'Esperado $%s · Contado $%s · Diferencia $%s',
                number_format($resultado['expected'], 2),
                number_format($resultado['counted'], 2),
                number_format($resultado['difference'], 2),
            ))
            ->{$resultado['difference'] === 0.0 ? 'success' : 'warning'}()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, Reservation> */
    protected function llegadasDeHoy(): Collection
    {
        if (! $this->hotelId) {
            return collect();
        }

        return Reservation::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('hotel_id', $this->hotelId)
            ->llegadasDe(now())
            ->whereNull('stay_id')
            ->with(['room:id,number', 'roomType:id,name'])
            ->orderBy('check_in_date')
            ->get();
    }

    protected function resetCheckinForm(): void
    {
        $habitacion = $this->selectedRoom;
        $reserva = $this->selectedReservation;

        $this->checkinForm = [
            'expected_check_out' => $reserva
                ? $reserva->check_out_date->toDateString()
                : now()->addDay()->toDateString(),
            'adults' => $reserva ? (int) $reserva->adults : 1,
            'children' => $reserva ? (int) $reserva->children : 0,
            'rate_per_night' => $reserva
                ? (float) $reserva->rate_per_night
                : (float) ($habitacion?->roomType?->base_rate ?? 0),
            'third_party_id' => $reserva?->third_party_id,
            'third_party_label' => null,
            'tax_exempt' => false,
            'tax_exempt_reason' => '',
            'notes' => $reserva?->notes,
            // Huésped titular
            'guest_id' => $reserva?->guest_id,
            'guest_label' => null,
            'guest_search' => '',
            'guest_first_name' => $reserva && ! $reserva->guest_id ? $reserva->guest_name : '',
            'guest_last_name' => '',
            'guest_document_type' => 'cc',
            'guest_document' => '',
            'guest_nationality' => '',
            'guest_phone' => $reserva?->guest_phone ?? '',
            'guest_email' => $reserva?->guest_email ?? '',
            'guest_origin' => '',
            'guest_destination' => '',
            'guest_purpose' => null,
        ];
    }

    protected function resetCheckoutForm(): void
    {
        $this->checkoutForm = [
            'payment_method' => 'cash',
            'cash_received' => null,
            'account_id' => PaymentAccountResolver::forMethod('cash'),
            'invoice_kind' => $this->currentHotel?->default_invoice_kind ?: 'pos',
            'third_party_id' => null,
            'third_party_label' => null,
            'no_invoice_reason' => '',
            // contado | credito. Arranca de contado: fiar es la excepcion.
            'modo' => 'contado',
        ];
    }

    protected function resetChargeForm(): void
    {
        $this->chargeForm = [
            'description' => '',
            'quantity' => 1,
            'unit_price' => null,
            'tax_id' => null,
        ];
    }

    protected function cerrarSubmodales(): void
    {
        $this->adjustOpen = false;
        $this->moveOpen = false;
        $this->extendOpen = false;
        $this->depositOpen = false;
        $this->customerModalOpen = false;
    }

    /** Cuando cambia el método de pago, la cuenta contable lo sigue. */
    public function updatedCheckoutForm($value, $key): void
    {
        if ($key === 'payment_method') {
            $this->checkoutForm['account_id'] = PaymentAccountResolver::forMethod((string) $value);

            if (! Vuelto::aplica((string) $value)) {
                $this->checkoutForm['cash_received'] = null;
            }
        }
    }

    /** @return array<int, string> */
    public function getTaxOptionsProperty(): array
    {
        return Tax::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('type', 'vat')
            ->where('is_active', true)
            ->orderBy('rate')
            ->get(['id', 'name', 'rate'])
            ->mapWithKeys(fn (Tax $t) => [$t->id => $t->name.' ('.rtrim(rtrim(number_format((float) $t->rate, 2, ',', '.'), '0'), ',').'%)'])
            ->all();
    }

    public function getHoraDeSalidaProperty(): string
    {
        return $this->currentHotel?->checkOutTime() ?? '12:00';
    }

    public function formatearHora(?Carbon $momento): string
    {
        return $momento?->format(ClockFormat::datetime()) ?? '—';
    }

    protected function avisar(string $tipo, string $titulo, ?string $cuerpo = null): void
    {
        $n = Notification::make()->title($titulo);

        if ($cuerpo) {
            $n->body($cuerpo);
        }

        match ($tipo) {
            'success' => $n->success(),
            'danger' => $n->danger()->persistent(),
            'warning' => $n->warning(),
            default => $n->info(),
        };

        $n->send();
    }
}
