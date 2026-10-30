<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Guest;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\Location;
use App\Services\Inventory\DescargaDeVenta;
use App\Support\HotelSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El ciclo de la estadía: entrar, quedarse, cambiarse de habitación, salir.
 *
 * Lo que decide si una habitación se puede dar está en HotelAvailability y no
 * aquí. Lo que decide cuánto se cobra está en HotelFolio. Este archivo es el
 * que mueve el estado: quién está dentro, en qué habitación, desde cuándo y
 * hasta cuándo.
 */
class HotelStayEngine
{
    public function __construct(
        protected HotelAvailability $disponibilidad,
        protected HotelFolio $folio,
        protected HotelStayNumberer $numerador,
    ) {}

    /**
     * Check-in sin reserva: el que llega preguntando si hay habitación.
     *
     * @param  array{hotel_id: int, room_id: int, expected_check_out: string,
     *               adults?: int, children?: int, rate_per_night?: float|null,
     *               third_party_id?: int|null, primary_guest_id?: int|null,
     *               guest_ids?: list<int>, tax_exempt?: bool, tax_exempt_reason?: string|null,
     *               notes?: string|null, check_in_at?: string|null}  $datos
     */
    public function checkIn(array $datos): Stay
    {
        return $this->abrir($datos, null);
    }

    /**
     * Check-in de una reserva: la ancla a la habitación y abre el folio.
     *
     * Si la reserva no tenía habitación asignada, se asigna la que venga en
     * los datos. Es el caso normal: se aparta un tipo y el número se decide
     * cuando el huésped está en el mostrador.
     *
     * @param  array<string, mixed>  $datos
     */
    public function checkInDeReserva(Reservation $reserva, array $datos = []): Stay
    {
        if (! $reserva->puedeHacerCheckIn()) {
            throw new RuntimeException(
                $reserva->stay_id
                    ? 'Esta reserva ya tiene una estadía abierta ('.$reserva->stay?->fullNumber().').'
                    : 'Esta reserva está '.strtolower(Reservation::STATUSES[$reserva->status] ?? $reserva->status)
                        .' y no se le puede hacer check-in.'
            );
        }

        $roomId = (int) ($datos['room_id'] ?? $reserva->room_id ?? 0);

        if ($roomId <= 0) {
            throw new RuntimeException(
                'Esta reserva aparta un tipo de habitación pero no un número. '
                .'Elige la habitación concreta para hacer el check-in.'
            );
        }

        // Lo pactado con el cliente manda sobre los valores por defecto: la
        // tarifa que se le prometió por teléfono es la que se le cobra.
        $datos = array_merge([
            'hotel_id' => (int) $reserva->hotel_id,
            'expected_check_out' => $reserva->check_out_date->toDateString(),
            'adults' => (int) $reserva->adults,
            'children' => (int) $reserva->children,
            'rate_per_night' => (float) $reserva->rate_per_night,
            // Si al reservar se pacto un precio cerrado, se respeta. Si no,
            // las noches se cobran por temporada igual que al cotizar.
            'rate_is_fixed' => (bool) $reserva->rate_is_fixed,
            'third_party_id' => $reserva->third_party_id,
            'primary_guest_id' => $reserva->guest_id,
            'notes' => $reserva->notes,
        ], array_filter($datos, fn ($valor) => $valor !== null));

        $datos['room_id'] = $roomId;

        return $this->abrir($datos, $reserva);
    }

    /**
     * Mueve la fecha de salida.
     *
     * Extender vuelve a pasar por disponibilidad ignorando la propia estadía:
     * las noches de más pueden chocar con la reserva que entra después, y
     * enterarse de eso el día de la llegada del otro cliente es un problema
     * con dos familias en el lobby.
     *
     * Acortar también está permitido, pero no por debajo de las noches que ya
     * durmió: esas ya se cobraron y se consumieron.
     */
    public function cambiarFechaDeSalida(Stay $estadia, Carbon|string $nuevaSalida): Stay
    {
        $this->exigirAbierta($estadia);

        $nueva = ($nuevaSalida instanceof Carbon ? $nuevaSalida->copy() : Carbon::parse($nuevaSalida))->startOfDay();
        $entrada = $estadia->check_in_at->copy()->startOfDay();

        if (! $nueva->gt($entrada)) {
            throw new RuntimeException('La salida tiene que ser posterior al día de entrada.');
        }

        $nochesDormidas = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->noches()
            ->count();

        if ($nochesDormidas > 0 && $this->disponibilidad->noches($entrada, $nueva) < $nochesDormidas) {
            throw new RuntimeException(sprintf(
                'El folio ya tiene %d noche(s) cargada(s). Para acortar la estadía, '
                .'quita primero del folio las noches que no se van a cobrar.',
                $nochesDormidas,
            ));
        }

        if ($nueva->gt($estadia->expected_check_out)) {
            $this->disponibilidad->exigirLibre(
                (int) $estadia->room_id,
                $estadia->expected_check_out,
                $nueva,
                ignorarEstadiaId: (int) $estadia->id,
            );
        }

        $estadia->update([
            'expected_check_out' => $nueva->toDateString(),
            'nights' => $this->disponibilidad->noches($entrada, $nueva),
        ]);

        $estadia = $estadia->fresh();
        $this->folio->cargarNochesPendientes($estadia);

        return $estadia->fresh();
    }

    /**
     * Traslada al huésped a otra habitación.
     *
     * El folio se queda: es la cuenta de la persona, no de la habitación. Lo
     * que cambia es dónde duerme, y desde esta noche en adelante. Las noches
     * ya cobradas no se reescriben porque sí se durmieron en la otra
     * habitación, y el folio es el registro de lo que pasó.
     */
    public function cambiarHabitacion(Stay $estadia, int $roomId, string $motivo = ''): Stay
    {
        $this->exigirAbierta($estadia);

        if ((int) $estadia->room_id === $roomId) {
            return $estadia;
        }

        $nueva = Room::query()
            ->where('hotel_id', $estadia->hotel_id)
            ->with('roomType')
            ->find($roomId);

        if (! $nueva) {
            throw new RuntimeException('La habitación seleccionada no existe en este hotel.');
        }

        $desde = now()->startOfDay();
        $hasta = $estadia->expected_check_out->copy()->startOfDay();

        if (! $hasta->gt($desde)) {
            $hasta = $desde->copy()->addDay();
        }

        $this->disponibilidad->exigirLibre($nueva, $desde, $hasta, ignorarEstadiaId: (int) $estadia->id);

        $anterior = $estadia->room;

        DB::transaction(function () use ($estadia, $nueva, $anterior, $motivo) {
            $estadia->update([
                'room_id' => $nueva->id,
                'room_type_id' => $nueva->room_type_id,
                'notes' => trim(($estadia->notes ?? '')."\nTraslado de hab. ".($anterior?->number ?? '—')
                    .' a '.$nueva->number.($motivo !== '' ? ': '.$motivo : '')),
            ]);

            // La que deja queda para aseo, no disponible: hay que entrar a
            // tenderla antes de dársela a nadie.
            $anterior?->update(['status' => Room::STATUS_CLEANING]);
            $nueva->update(['status' => Room::STATUS_OCCUPIED]);
        });

        return $estadia->fresh();
    }

    /**
     * Cotiza la salida sin cerrar nada.
     *
     * Es lo que recepción le muestra al huésped antes de cobrarle. Deja el
     * folio ya liquidado —con las noches que faltaban y la noche extra si la
     * salida es tardía— porque la cuenta que se le enseña tiene que ser la
     * misma que se le cobra. Mostrar una cifra y cobrar otra es la peor forma
     * de terminar una estadía.
     *
     * @return array{noches: int, noche_extra: bool, hora_pactada: string,
     *               total: float, saldo: float, estadia: Stay}
     */
    public function liquidar(Stay $estadia, Carbon|string|null $salida = null): array
    {
        $this->exigirAbierta($estadia);

        $salida = $salida
            ? ($salida instanceof Carbon ? $salida->copy() : Carbon::parse($salida))
            : now();

        $hotel = $estadia->hotel;
        $entrada = $estadia->check_in_at->copy()->startOfDay();
        $diaDeSalida = $salida->copy()->startOfDay();

        // Día de entrada = día de salida: un «day use» se cobra como noche.
        $noches = max(1, (int) $entrada->diffInDays($diaDeSalida));
        $extra = $this->saleTarde($hotel, $salida);

        if ($extra) {
            $noches++;
        }

        DB::transaction(function () use ($estadia, $entrada, $noches) {
            // Las noches válidas son exactamente estas. Lo que sobre se quita
            // —el que se va antes de lo pactado no paga la noche que no
            // durmió— y lo que falte se carga.
            $validas = [];

            for ($i = 0; $i < $noches; $i++) {
                $validas[] = $entrada->copy()->addDays($i)->toDateString();
            }

            $cargadas = StayCharge::query()
                ->where('stay_id', $estadia->id)
                ->noches()
                ->get(['id', 'night_date']);

            foreach ($cargadas as $cargo) {
                if (! in_array($cargo->night_date->toDateString(), $validas, true)) {
                    $cargo->delete();
                }
            }

            $yaEstan = $cargadas
                ->map(fn ($cargo) => $cargo->night_date->toDateString())
                ->all();

            foreach ($validas as $noche) {
                if (! in_array($noche, $yaEstan, true)) {
                    $this->folio->agregarNoche($estadia, $noche);
                }
            }

            $estadia->forceFill(['nights' => $noches])->save();
            $this->folio->recalcular($estadia);
        });

        $estadia = $estadia->fresh();

        return [
            'noches' => $noches,
            'noche_extra' => $extra,
            'hora_pactada' => $hotel?->checkOutTime() ?? '12:00',
            'total' => (float) $estadia->total,
            'saldo' => $estadia->saldo(),
            'estadia' => $estadia,
        ];
    }

    /**
     * Cierra sin factura: la cortesía, la habitación de cortesía al
     * proveedor, el error que se anula después de haber cargado consumos.
     *
     * No toca la caja —no entró plata— pero SÍ descarga inventario: la cerveza
     * del minibar salió de la nevera aunque no se cobrara. Es la misma regla
     * que el «casa invita» del restaurante, y por eso usa el mismo servicio.
     */
    public function cerrarSinFacturar(Stay $estadia, string $motivo): Stay
    {
        $this->exigirAbierta($estadia);

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException(
                'Cerrar un folio sin cobrar necesita motivo escrito: es plata que el hotel '
                .'deja de recibir y alguien tiene que poder responder por qué.'
            );
        }

        return DB::transaction(function () use ($estadia, $motivo) {
            $this->descargarInventario($estadia, 'Cierre sin facturar '.$estadia->fullNumber());

            $estadia->update([
                'status' => Stay::STATUS_CLOSED,
                'check_out_at' => now(),
                'closed_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'cancel_reason' => $motivo,
            ]);

            $this->liberarHabitacion($estadia, Room::STATUS_CLEANING);
            $this->cerrarReserva($estadia);

            return $estadia->fresh();
        });
    }

    /**
     * Cierra la estadía ya facturada.
     *
     * La llama HotelBillingEngine dentro de su propia transacción, justo
     * después de emitir la factura y registrar el pago. Vive aquí y no allá
     * porque las transiciones de estado —la habitación que pasa a aseo, la
     * reserva que queda terminada— son de este archivo: escritas en dos
     * sitios, un día una habitación se quedaría ocupada para siempre.
     */
    public function cerrarFacturada(
        Stay $estadia,
        int $invoiceId,
        float $pagado,
        ?int $cashSessionId = null,
    ): Stay {
        $this->exigirAbierta($estadia);

        $estadia->update([
            'status' => Stay::STATUS_CLOSED,
            'check_out_at' => $estadia->check_out_at ?: now(),
            'closed_at' => now(),
            'closed_by_user_id' => Auth::id(),
            'sale_invoice_id' => $invoiceId,
            'paid_total' => round($pagado, 2),
            'cash_register_session_id' => $cashSessionId,
        ]);

        $this->liberarHabitacion($estadia, Room::STATUS_CLEANING);
        $this->cerrarReserva($estadia);

        return $estadia->fresh();
    }

    /**
     * Anula una estadía recién abierta: el check-in equivocado.
     *
     * Se exige que no tenga consumos ni factura. Con consumos ya cargados lo
     * que corresponde es cerrarla sin facturar, que deja rastro de qué salió
     * del almacén; anularla borraría ese rastro.
     */
    public function anular(Stay $estadia, string $motivo): Stay
    {
        $this->exigirAbierta($estadia);

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('El motivo de la anulación es obligatorio.');
        }

        if ($estadia->sale_invoice_id) {
            throw new RuntimeException(
                'Esta estadía ya tiene factura ('.$estadia->invoice?->fullNumber().'). '
                .'Lo que corresponde es una nota crédito, no anular el folio.'
            );
        }

        $consumos = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->consumos()
            ->count();

        if ($consumos > 0) {
            throw new RuntimeException(
                'El folio tiene '.$consumos.' consumo(s) cargado(s). Usa «cerrar sin facturar» '
                .'para que quede el rastro de lo que salió del almacén.'
            );
        }

        return DB::transaction(function () use ($estadia, $motivo) {
            StayCharge::query()->where('stay_id', $estadia->id)->delete();

            $estadia->update([
                'status' => Stay::STATUS_CANCELLED,
                'cancel_reason' => $motivo,
                'closed_at' => now(),
                'closed_by_user_id' => Auth::id(),
                'room_total' => 0,
                'consumption_total' => 0,
                'discount_total' => 0,
                'tax_total' => 0,
                'total' => 0,
            ]);

            // La habitación vuelve a disponible, no a aseo: nadie durmió ahí.
            $this->liberarHabitacion($estadia, Room::STATUS_AVAILABLE);

            // La reserva vuelve a estar viva: el check-in fue el error, no la
            // reserva, y el cliente sigue esperando su habitación.
            if ($estadia->reservation && $estadia->reservation->status === Reservation::STATUS_CHECKED_IN) {
                $estadia->reservation->update([
                    'status' => Reservation::STATUS_CONFIRMED,
                    'stay_id' => null,
                ]);
            }

            return $estadia->fresh();
        });
    }

    /** La habitación quedó tendida y vuelve al inventario vendible. */
    public function marcarAseada(Room $habitacion): Room
    {
        if ($habitacion->status === Room::STATUS_OCCUPIED) {
            throw new RuntimeException(
                'La habitación '.$habitacion->number.' está ocupada. '
                .'El aseo se marca cuando el huésped ya salió.'
            );
        }

        $habitacion->update(['status' => Room::STATUS_AVAILABLE]);

        return $habitacion->fresh();
    }

    /**
     * Registra quién duerme en la habitación.
     *
     * El titular más los acompañantes: la tarjeta de registro hotelero los
     * pide todos, no solo al que paga.
     *
     * @param  list<int>  $guestIds
     */
    public function registrarHuespedes(Stay $estadia, array $guestIds, ?int $titularId = null): Stay
    {
        $validos = Guest::query()
            ->where('company_id', $estadia->company_id)
            ->whereIn('id', $guestIds)
            ->pluck('id')
            ->all();

        $titularId = $titularId && in_array($titularId, $validos, true)
            ? $titularId
            : ($validos[0] ?? null);

        DB::transaction(function () use ($estadia, $validos, $titularId) {
            $estadia->guests()->detach();

            foreach ($validos as $id) {
                $estadia->guests()->attach($id, [
                    'company_id' => $estadia->company_id,
                    'is_primary' => $id === $titularId,
                ]);
            }

            if ($titularId) {
                $estadia->update(['primary_guest_id' => $titularId]);
            }
        });

        return $estadia->fresh();
    }

    /**
     * Abre la estadía. Es el único camino: lo llaman el check-in con reserva
     * y el de mostrador, para que las dos entradas dejen exactamente el mismo
     * estado.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function abrir(array $datos, ?Reservation $reserva): Stay
    {
        $hotel = Hotel::query()
            ->where('id', $datos['hotel_id'] ?? 0)
            ->where('active', true)
            ->first();

        if (! $hotel) {
            throw new RuntimeException('El hotel seleccionado no existe o está inactivo.');
        }

        $habitacion = Room::query()
            ->where('hotel_id', $hotel->id)
            ->with('roomType')
            ->find($datos['room_id'] ?? 0);

        if (! $habitacion) {
            throw new RuntimeException('La habitación seleccionada no existe en este hotel.');
        }

        $entrada = ! empty($datos['check_in_at'])
            ? Carbon::parse($datos['check_in_at'])
            : now();

        $salida = Carbon::parse($datos['expected_check_out'] ?? '')->startOfDay();

        if (! $salida->gt($entrada->copy()->startOfDay())) {
            throw new RuntimeException(
                'La salida tiene que ser al menos el día siguiente. '
                .'Una estadía de una noche va del día que llega al día siguiente.'
            );
        }

        $this->disponibilidad->exigirLibre(
            $habitacion,
            $entrada,
            $salida,
            ignorarReservaId: $reserva?->id,
        );

        $adultos = max(1, (int) ($datos['adults'] ?? 1));
        $ninos = max(0, (int) ($datos['children'] ?? 0));
        $personas = $adultos + $ninos;
        $tope = (int) ($habitacion->max_occupancy ?: $habitacion->roomType?->capacidad() ?: 0);

        if ($tope > 0 && $personas > $tope) {
            throw new RuntimeException(sprintf(
                'La habitación %s admite %d persona(s) y se están registrando %d.',
                $habitacion->number,
                $tope,
                $personas,
            ));
        }

        // Por defecto cada noche vale lo que valga su fecha; el folio las
        // cobra una por una. `rate_per_night` queda como la representativa,
        // que es la que se muestra en el mapa y en la cuenta.
        $cotizacion = app(HotelTarifas::class)->cotizar(
            $habitacion->roomType,
            $entrada,
            $salida,
            $adultos,
        );

        $escrita = $datos['rate_per_night'] ?? null;
        $tarifaFija = (bool) ($datos['rate_is_fixed'] ?? false);

        if ($escrita !== null && $escrita !== '') {
            $tarifa = (float) $escrita;

            // Una tarifa distinta a la cotizada es una tarifa negociada, y
            // entonces las temporadas no se le aplican por encima.
            if (abs($tarifa - $cotizacion['promedio']) > 0.01) {
                $tarifaFija = true;
            } elseif (! $tarifaFija) {
                $tarifa = $cotizacion['promedio'];
            }
        } else {
            $tarifa = $cotizacion['promedio'];
        }

        if ($tarifa <= 0) {
            throw new RuntimeException(
                'La habitación no tiene tarifa. Configura el precio por noche en el tipo '
                .'«'.($habitacion->roomType?->name ?? '—').'» antes de alojar a alguien.'
            );
        }

        $exenta = (bool) ($datos['tax_exempt'] ?? false);
        $motivoExencion = trim((string) ($datos['tax_exempt_reason'] ?? ''));

        if ($exenta && $motivoExencion === '') {
            // La exención del art. 481 ET hay que poder sustentarla ante la
            // DIAN. Sin motivo escrito, el hotel se queda sin con qué.
            $motivoExencion = 'Turista extranjero no residente (art. 481 ET)';
        }

        $noches = $this->disponibilidad->noches($entrada->copy()->startOfDay(), $salida);

        $estadia = DB::transaction(function () use (
            $hotel, $habitacion, $reserva, $entrada, $salida, $noches,
            $adultos, $ninos, $tarifa, $tarifaFija, $exenta, $motivoExencion, $datos,
        ) {
            $estadia = Stay::create([
                'company_id' => $hotel->company_id,
                'hotel_id' => $hotel->id,
                'room_id' => $habitacion->id,
                'room_type_id' => $habitacion->room_type_id,
                'reservation_id' => $reserva?->id,
                'third_party_id' => $datos['third_party_id'] ?? null,
                'primary_guest_id' => $datos['primary_guest_id'] ?? null,
                'prefix' => 'H',
                'number' => $this->numerador->next($hotel->company, 'H'),
                'check_in_at' => $entrada,
                'expected_check_out' => $salida->toDateString(),
                'nights' => $noches,
                'adults' => $adultos,
                'children' => $ninos,
                'rate_per_night' => $tarifa,
                'rate_is_fixed' => $tarifaFija,
                'status' => Stay::STATUS_OPEN,
                'tax_exempt' => $exenta,
                'tax_exempt_reason' => $exenta ? $motivoExencion : null,
                'notes' => $datos['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $habitacion->update(['status' => Room::STATUS_OCCUPIED]);

            if ($reserva) {
                $reserva->update([
                    'status' => Reservation::STATUS_CHECKED_IN,
                    'room_id' => $habitacion->id,
                    'stay_id' => $estadia->id,
                ]);
            }

            return $estadia;
        });

        if (! empty($datos['guest_ids'])) {
            $estadia = $this->registrarHuespedes(
                $estadia,
                (array) $datos['guest_ids'],
                $datos['primary_guest_id'] ?? null,
            );
        }

        // La primera noche se carga de entrada: el folio tiene que mostrar
        // algo desde el minuto uno, si no recepción cree que no se guardó.
        $this->folio->cargarNochesPendientes($estadia);

        return $estadia->fresh();
    }

    /**
     * Si la salida es tan tarde que suma otra noche.
     *
     * No basta pasarse de la hora pactada: casi todos los hoteles dan una
     * holgura, y cobrarle una noche completa a quien entregó la habitación
     * cinco minutos tarde es una pelea en el mostrador. La holgura y el cobro
     * se configuran por empresa.
     */
    protected function saleTarde(?Hotel $hotel, Carbon $salida): bool
    {
        if (! $hotel || ! HotelSettings::cobraSalidaTardia($hotel->company)) {
            return false;
        }

        $limite = Carbon::parse(
            $salida->toDateString().' '.$hotel->checkOutTime().':00'
        )->addMinutes(HotelSettings::holguraDeSalida($hotel->company));

        return $salida->gt($limite);
    }

    /**
     * Descarga del almacén lo que se consumió.
     *
     * Usa el mismo servicio que la factura de venta y el cierre de orden del
     * restaurante, para que un producto salga igual del inventario sin
     * importar por dónde salió del hotel. Un inventario que se descarga
     * distinto según el camino no se puede cuadrar contra nada.
     */
    protected function descargarInventario(Stay $estadia, string $descripcion): void
    {
        $sede = $estadia->hotel?->defaultLocation
            ?: Location::query()
                ->where('company_id', $estadia->company_id)
                ->where('active', true)
                ->orderByDesc('is_main')
                ->orderBy('id')
                ->first();

        if (! $sede) {
            return;
        }

        $descarga = app(DescargaDeVenta::class);

        $cargos = StayCharge::query()
            ->where('stay_id', $estadia->id)
            ->where('kind', StayCharge::KIND_PRODUCT)
            ->whereNotNull('product_id')
            ->with('product')
            ->get();

        foreach ($cargos as $cargo) {
            if (! $cargo->product || ! $cargo->product->track_inventory) {
                continue;
            }

            $descarga->descargar(
                $cargo->product,
                $sede,
                (float) $cargo->quantity,
                [
                    'type' => 'sale',
                    'date' => $cargo->charge_date?->toDateString() ?? now()->toDateString(),
                    'reference_type' => Stay::class,
                    'reference_id' => $estadia->id,
                    'reference_number' => $estadia->fullNumber(),
                    'third_party_id' => $estadia->third_party_id,
                    'description' => $descripcion.' — '.$cargo->description,
                ],
                permitirNegativo: true,
            );
        }
    }

    /** Deja libre la habitación de una estadía que termina. */
    protected function liberarHabitacion(Stay $estadia, string $estado): void
    {
        Room::query()
            ->where('id', $estadia->room_id)
            ->where('status', Room::STATUS_OCCUPIED)
            ->update(['status' => $estado]);
    }

    /** Marca la reserva como terminada cuando la estadía se cierra. */
    protected function cerrarReserva(Stay $estadia): void
    {
        if ($estadia->reservation && $estadia->reservation->status === Reservation::STATUS_CHECKED_IN) {
            $estadia->reservation->update(['status' => Reservation::STATUS_COMPLETED]);
        }
    }

    protected function exigirAbierta(Stay $estadia): void
    {
        if (! $estadia->estaAbierta()) {
            throw new RuntimeException(
                'La estadía '.$estadia->fullNumber().' está '
                .strtolower(Stay::STATUSES[$estadia->status] ?? $estadia->status).'.'
            );
        }
    }
}
