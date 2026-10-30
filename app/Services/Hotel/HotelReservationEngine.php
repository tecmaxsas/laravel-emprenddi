<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Hotel;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reservas: crear, confirmar, anclar a una habitación, cancelar.
 *
 * Toda operación que compromete fechas pasa por HotelAvailability. No hay un
 * segundo criterio de disponibilidad en este archivo, a propósito: si la regla
 * del solapamiento estuviera también aquí, el día que cambie quedaría
 * cambiada en un sitio y vieja en el otro, y el resultado de eso es vender dos
 * veces la misma noche.
 */
class HotelReservationEngine
{
    public function __construct(
        protected HotelAvailability $disponibilidad,
    ) {}

    /**
     * Crea una reserva.
     *
     * `room_id` es opcional: el cliente llama y aparta «una doble para el
     * viernes», y el número concreto se decide después. Lo que se aparta es el
     * tipo. Si viene habitación, se verifica que esté libre antes de prometer
     * nada.
     *
     * @param  array{hotel_id: int, room_id?: int|null, room_type_id?: int|null,
     *               check_in_date: string, check_out_date: string,
     *               guest_name: string, guest_phone?: string|null, guest_email?: string|null,
     *               third_party_id?: int|null, guest_id?: int|null,
     *               adults?: int, children?: int, rate_per_night?: float|null,
     *               deposit_amount?: float, source?: string, notes?: string|null}  $datos
     */
    public function crear(array $datos): Reservation
    {
        $hotel = Hotel::query()
            ->where('id', $datos['hotel_id'] ?? 0)
            ->where('active', true)
            ->first();

        if (! $hotel) {
            throw new RuntimeException('El hotel seleccionado no existe o está inactivo.');
        }

        [$entrada, $salida] = $this->disponibilidad->normalizar(
            $datos['check_in_date'] ?? '',
            $datos['check_out_date'] ?? '',
        );

        $nombre = trim((string) ($datos['guest_name'] ?? ''));

        if ($nombre === '') {
            throw new RuntimeException('La reserva necesita a nombre de quién queda.');
        }

        $adultos = max(1, (int) ($datos['adults'] ?? 1));
        $ninos = max(0, (int) ($datos['children'] ?? 0));

        $habitacion = null;
        $tipo = null;

        if (! empty($datos['room_id'])) {
            $habitacion = Room::query()
                ->where('hotel_id', $hotel->id)
                ->with('roomType')
                ->find($datos['room_id']);

            if (! $habitacion) {
                throw new RuntimeException('La habitación seleccionada no existe en este hotel.');
            }

            $this->disponibilidad->exigirLibre($habitacion, $entrada, $salida);
            $tipo = $habitacion->roomType;
        } elseif (! empty($datos['room_type_id'])) {
            $tipo = RoomType::query()
                ->where('hotel_id', $hotel->id)
                ->find($datos['room_type_id']);

            if (! $tipo) {
                throw new RuntimeException('El tipo de habitación seleccionado no existe en este hotel.');
            }

            // Sin habitación concreta, lo que hay que comprobar es que quede
            // al menos una de ese tipo. Prometer una doble cuando las tres
            // están apartadas es el mismo sobrecupo, solo que más tarde.
            $libres = $this->disponibilidad->habitacionesLibres($hotel->id, $entrada, $salida, (int) $tipo->id);

            if ($libres->isEmpty()) {
                throw new RuntimeException(sprintf(
                    'No quedan habitaciones de tipo %s entre el %s y el %s.',
                    $tipo->name,
                    $entrada->format('d/m/Y'),
                    $salida->format('d/m/Y'),
                ));
            }
        } else {
            throw new RuntimeException('Indica la habitación o, al menos, el tipo de habitación que se aparta.');
        }

        $this->exigirCapacidad($tipo, $habitacion, $adultos, $ninos);

        $noches = $this->disponibilidad->noches($entrada, $salida);

        [$tarifa, $estimado, $fija] = $this->cotizar($tipo, $entrada, $salida, $adultos, $datos);

        return DB::transaction(function () use (
            $hotel, $habitacion, $tipo, $entrada, $salida, $noches,
            $nombre, $adultos, $ninos, $tarifa, $estimado, $fija, $datos,
        ) {
            $reserva = Reservation::create([
                'company_id' => $hotel->company_id,
                'hotel_id' => $hotel->id,
                'room_id' => $habitacion?->id,
                'room_type_id' => $tipo?->id,
                'meal_plan_id' => $datos['meal_plan_id'] ?? null,
                'third_party_id' => $datos['third_party_id'] ?? null,
                'guest_id' => $datos['guest_id'] ?? null,
                'guest_name' => $nombre,
                'guest_phone' => $datos['guest_phone'] ?? null,
                'guest_email' => $datos['guest_email'] ?? null,
                'check_in_date' => $entrada->toDateString(),
                'check_out_date' => $salida->toDateString(),
                'nights' => $noches,
                'adults' => $adultos,
                'children' => $ninos,
                'rate_per_night' => $tarifa,
                'rate_is_fixed' => $fija,
                'estimated_total' => $estimado,
                'deposit_amount' => max(0, (float) ($datos['deposit_amount'] ?? 0)),
                'source' => array_key_exists((string) ($datos['source'] ?? ''), Reservation::SOURCES)
                    ? $datos['source']
                    : 'direct',
                'status' => Reservation::STATUS_PENDING,
                'notes' => $datos['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            // El código se deriva del id: único por construcción, sin candados
            // ni contadores compartidos.
            $reserva->forceFill(['code' => 'R-'.str_pad((string) $reserva->id, 6, '0', STR_PAD_LEFT)])->save();

            return $reserva->fresh();
        });
    }

    /**
     * Qué se le va a cobrar: tarifa, total estimado y si quedó pactada.
     *
     * Por defecto cada noche vale lo que valga su fecha, que es lo que hace
     * que una estadía del 28 de diciembre al 3 de enero cueste lo correcto en
     * vez de un promedio inventado.
     *
     * **Cuándo queda pactada.** Si quien crea la reserva escribe una tarifa
     * distinta a la cotizada, es porque negoció un precio, y entonces se marca
     * como fija y las temporadas ya no la tocan. No es que el sistema lo
     * adivine cada vez: lo decide UNA vez, al crearla, y lo deja escrito en la
     * reserva para que después se vea y se pueda cambiar. Adivinarlo en cada
     * cobro sería que un día cobre distinto de lo pactado, y eso se descubre
     * con el huésped en el mostrador.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: float, 1: float, 2: bool}
     */
    protected function cotizar(
        ?RoomType $tipo,
        Carbon $entrada,
        Carbon $salida,
        int $adultos,
        array $datos,
    ): array {
        $noches = $this->disponibilidad->noches($entrada, $salida);
        $cotizacion = app(HotelTarifas::class)->cotizar($tipo, $entrada, $salida, $adultos);

        $escrita = $datos['rate_per_night'] ?? null;
        $fija = (bool) ($datos['rate_is_fixed'] ?? false);

        if ($escrita === null || $escrita === '') {
            return [$cotizacion['promedio'], $cotizacion['total'], $fija];
        }

        $tarifa = (float) $escrita;

        if (abs($tarifa - $cotizacion['promedio']) > 0.01) {
            $fija = true;
        }

        return $fija
            ? [$tarifa, round($tarifa * $noches, 2), true]
            : [$cotizacion['promedio'], $cotizacion['total'], false];
    }

    /**
     * Ancla la reserva a una habitación concreta.
     *
     * Es la operación que el recepcionista hace el día de la llegada, o antes
     * si quiere dejar todo listo. Se vuelve a comprobar la disponibilidad
     * ignorando esta misma reserva: si no, se chocaría contra sí misma cuando
     * ya tenía esa habitación asignada.
     */
    public function asignarHabitacion(Reservation $reserva, int $roomId): Reservation
    {
        if (! $reserva->estaViva()) {
            throw new RuntimeException(
                'Esta reserva está '.strtolower(Reservation::STATUSES[$reserva->status] ?? $reserva->status)
                .' y ya no se puede asignar.'
            );
        }

        $habitacion = Room::query()
            ->where('hotel_id', $reserva->hotel_id)
            ->with('roomType')
            ->find($roomId);

        if (! $habitacion) {
            throw new RuntimeException('La habitación seleccionada no existe en este hotel.');
        }

        $this->disponibilidad->exigirLibre(
            $habitacion,
            $reserva->check_in_date,
            $reserva->check_out_date,
            ignorarReservaId: (int) $reserva->id,
        );

        $this->exigirCapacidad(
            $habitacion->roomType,
            $habitacion,
            (int) $reserva->adults,
            (int) $reserva->children,
        );

        $reserva->update([
            'room_id' => $habitacion->id,
            'room_type_id' => $habitacion->room_type_id,
        ]);

        return $reserva->fresh();
    }

    /** Quita la habitación y deja la reserva apartando solo el tipo. */
    public function liberarHabitacion(Reservation $reserva): Reservation
    {
        if (! $reserva->estaViva()) {
            throw new RuntimeException('Solo se puede reasignar una reserva viva.');
        }

        $reserva->update(['room_id' => null]);

        return $reserva->fresh();
    }

    public function confirmar(Reservation $reserva): Reservation
    {
        if ($reserva->status === Reservation::STATUS_CONFIRMED) {
            return $reserva;
        }

        if ($reserva->status !== Reservation::STATUS_PENDING) {
            throw new RuntimeException(
                'Solo se confirma una reserva por confirmar. Esta está '
                .strtolower(Reservation::STATUSES[$reserva->status] ?? $reserva->status).'.'
            );
        }

        $reserva->update([
            'status' => Reservation::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        return $reserva->fresh();
    }

    /**
     * Cambia fechas, personas o tarifa de una reserva viva.
     *
     * Mover fechas vuelve a pasar por disponibilidad: extender dos noches
     * puede chocar con la reserva siguiente, y descubrirlo el día de la salida
     * es un problema con dos clientes de frente.
     *
     * @param  array{check_in_date?: string, check_out_date?: string, adults?: int,
     *               children?: int, rate_per_night?: float, notes?: string|null,
     *               guest_name?: string, guest_phone?: string|null, guest_email?: string|null}  $datos
     */
    public function modificar(Reservation $reserva, array $datos): Reservation
    {
        if (! $reserva->estaViva()) {
            throw new RuntimeException(
                'Esta reserva está '.strtolower(Reservation::STATUSES[$reserva->status] ?? $reserva->status)
                .' y ya no se puede modificar.'
            );
        }

        [$entrada, $salida] = $this->disponibilidad->normalizar(
            $datos['check_in_date'] ?? $reserva->check_in_date,
            $datos['check_out_date'] ?? $reserva->check_out_date,
        );

        $adultos = max(1, (int) ($datos['adults'] ?? $reserva->adults));
        $ninos = max(0, (int) ($datos['children'] ?? $reserva->children));

        if ($reserva->room_id) {
            $habitacion = Room::query()->with('roomType')->find($reserva->room_id);

            if ($habitacion) {
                $this->disponibilidad->exigirLibre(
                    $habitacion,
                    $entrada,
                    $salida,
                    ignorarReservaId: (int) $reserva->id,
                );
                $this->exigirCapacidad($habitacion->roomType, $habitacion, $adultos, $ninos);
            }
        }

        $noches = $this->disponibilidad->noches($entrada, $salida);

        // Se recotiza: mover las fechas puede meter la estadia en otra
        // temporada, y dejar el total viejo seria prometerle al cliente un
        // precio que ya no corresponde a esas noches.
        [$tarifa, $estimado, $fija] = $this->cotizar(
            $reserva->roomType,
            $entrada,
            $salida,
            $adultos,
            $datos + ['rate_is_fixed' => (bool) $reserva->rate_is_fixed],
        );

        $reserva->update(array_filter([
            'check_in_date' => $entrada->toDateString(),
            'check_out_date' => $salida->toDateString(),
            'nights' => $noches,
            'adults' => $adultos,
            'children' => $ninos,
            'rate_per_night' => $tarifa,
            'rate_is_fixed' => $fija,
            'estimated_total' => $estimado,
            'guest_name' => isset($datos['guest_name']) ? trim((string) $datos['guest_name']) : null,
            'guest_phone' => $datos['guest_phone'] ?? null,
            'guest_email' => $datos['guest_email'] ?? null,
            'notes' => $datos['notes'] ?? null,
        ], fn ($valor) => $valor !== null));

        return $reserva->fresh();
    }

    public function cancelar(Reservation $reserva, string $motivo): Reservation
    {
        if (! $reserva->estaViva()) {
            throw new RuntimeException('Solo se cancela una reserva viva.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException(
                'El motivo de la cancelación es obligatorio: es lo que explica por qué se liberó '
                .'esa habitación y lo que sustenta si se devuelve o no el depósito.'
            );
        }

        $reserva->update([
            'status' => Reservation::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $motivo,
        ]);

        return $reserva->fresh();
    }

    /**
     * Qué pasa con el depósito cuando la reserva se cae.
     *
     * Nada, a propósito: la plata se queda como saldo a favor del cliente, que
     * es exactamente lo que es —entró al hotel y está registrada a su nombre—.
     *
     * Lo que el hotel haga después es una decisión suya y de cada caso:
     *
     *   · **Devolverlo** → se registra la devolución del anticipo.
     *   · **Retenerlo como penalidad** → se le factura la penalidad y el
     *     anticipo se cruza solo contra esa factura.
     *
     * El sistema no elige por el hotel, pero sí tiene que decírselo: un
     * depósito que desaparece en silencio cuando se cancela una reserva es
     * plata de un cliente que nadie vuelve a mirar.
     */
    public function avisoDelDeposito(Reservation $reserva): ?string
    {
        $recibido = $reserva->depositoRecibido();

        if ($recibido <= 0) {
            return null;
        }

        return sprintf(
            '%s tiene $%s a favor por el depósito de esta reserva. Devuélvanlo o factúrenlo como '
            .'penalidad: el saldo queda a su nombre hasta que decidan.',
            $reserva->customer?->name ?: $reserva->guest_name,
            number_format($recibido, 0, ',', '.'),
        );
    }

    /**
     * El cliente no apareció.
     *
     * Se separa de la cancelación porque no es lo mismo: una cancelación
     * avisada suele devolver el depósito y un no-show suele perderlo. Si los
     * dos casos quedaran con el mismo estado, no habría forma de medir cuántas
     * reservas se pierden por no-show, que es justo lo que decide si el hotel
     * empieza a exigir depósito.
     */
    public function noLlego(Reservation $reserva): Reservation
    {
        if (! $reserva->estaViva()) {
            throw new RuntimeException('Solo se marca como «no llegó» una reserva viva.');
        }

        if ($reserva->check_in_date->copy()->startOfDay()->gt(now()->startOfDay())) {
            throw new RuntimeException(
                'Todavía no es el día de la llegada: no se puede marcar como «no llegó» '
                .'una reserva de '.$reserva->check_in_date->format('d/m/Y').'.'
            );
        }

        $reserva->update([
            'status' => Reservation::STATUS_NO_SHOW,
            'cancelled_at' => now(),
        ]);

        return $reserva->fresh();
    }

    /**
     * La capacidad de la habitación es un tope físico, no una sugerencia.
     *
     * Cuatro personas en una sencilla no es una venta: es una queja y un
     * problema de seguridad. Se valida contra el tipo y contra la habitación,
     * que puede tener un tope propio más bajo.
     */
    protected function exigirCapacidad(?RoomType $tipo, ?Room $habitacion, int $adultos, int $ninos): void
    {
        $personas = $adultos + $ninos;

        if ($tipo && $personas > $tipo->capacidad()) {
            throw new RuntimeException(sprintf(
                'Una %s admite %d persona(s) y se están registrando %d.',
                $tipo->name,
                $tipo->capacidad(),
                $personas,
            ));
        }

        if ($habitacion && $habitacion->max_occupancy > 0 && $personas > (int) $habitacion->max_occupancy) {
            throw new RuntimeException(sprintf(
                'La habitación %s admite %d persona(s) y se están registrando %d.',
                $habitacion->number,
                $habitacion->max_occupancy,
                $personas,
            ));
        }
    }
}
