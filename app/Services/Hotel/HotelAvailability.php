<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * ¿Está libre esa habitación en esas fechas?
 *
 * Es LA pregunta del módulo y por eso está escrita una sola vez. La hacen
 * cuatro sitios por caminos distintos —crear una reserva, anclarla a una
 * habitación, hacer check-in sin reserva y pintar el mapa de recepción— y si
 * cada uno la respondiera a su manera bastaría que uno se quedara atrás para
 * vender dos veces la misma noche. Un sobrecupo no se arregla con un parche:
 * hay dos familias en el lobby y una sola habitación.
 *
 * EL CONVENIO DE RANGO, que es la decisión de la que depende todo:
 *
 *   [desde, hasta)  — `desde` inclusiva, `hasta` EXCLUSIVA.
 *
 * Quien sale el 10 deja la habitación libre para quien llega el 10. Lo que se
 * aparta son noches, no días: una estadía del 8 al 10 son dos noches (la del 8
 * y la del 9) y la mañana del 10 la habitación ya se puede entregar. Con el
 * convenio al revés —`hasta` inclusiva— el hotel pierde una noche vendible por
 * cada reserva, que en temporada alta es la mitad del inventario.
 *
 * Dos rangos se solapan si:  desde < otro_hasta  Y  hasta > otro_desde.
 *
 * QUÉ COMPROMETE UNA HABITACIÓN:
 *
 *  1. Una reserva viva (por confirmar o confirmada) que se solape.
 *  2. Una estadía abierta. Bloquea desde que entró hasta la salida pactada
 *     — o hasta mañana si ya se pasó de la fecha, porque el huésped está
 *     adentro esta noche aunque en el papel debía haberse ido ayer.
 *  3. Una estadía cerrada cuyo rango real todavía toque el que se consulta.
 *  4. El estado de la habitación: mantenimiento o bloqueada no se venden ni
 *     para dentro de un mes.
 *
 * Lo que NO compromete: una reserva cancelada, una que no llegó, y una que ya
 * hizo check-in (esa bloquea a través de su estadía; si contara dos veces, el
 * huésped se chocaría consigo mismo al pedir quedarse un día más).
 */
class HotelAvailability
{
    /**
     * Las habitaciones comprometidas en el rango, con el motivo de cada una.
     *
     * Es el único sitio que consulta el solapamiento. Todo lo demás —si una
     * habitación concreta está libre, cuáles quedan disponibles, qué decirle
     * al recepcionista— se deriva de aquí, y por eso resuelve el hotel
     * completo de una sola vez en vez de preguntar habitación por habitación.
     *
     * @return array<int, list<string>>  id de habitación => motivos
     */
    public function comprometidas(
        int $hotelId,
        Carbon|string $desde,
        Carbon|string $hasta,
        ?int $ignorarReservaId = null,
        ?int $ignorarEstadiaId = null,
    ): array {
        [$desde, $hasta] = $this->normalizar($desde, $hasta);

        $motivos = [];

        // 1 — Reservas vivas que se solapan.
        $reservas = Reservation::query()
            ->where('hotel_id', $hotelId)
            ->whereNotNull('room_id')
            ->queBloquean()
            ->whereDate('check_in_date', '<', $hasta->toDateString())
            ->whereDate('check_out_date', '>', $desde->toDateString())
            ->when($ignorarReservaId, fn ($q) => $q->where('id', '!=', $ignorarReservaId))
            ->get(['id', 'room_id', 'code', 'guest_name', 'check_in_date', 'check_out_date']);

        foreach ($reservas as $reserva) {
            $motivos[(int) $reserva->room_id][] = sprintf(
                'Reserva %s de %s (%s al %s)',
                $reserva->code,
                $reserva->guest_name,
                $reserva->check_in_date->format('d/m/Y'),
                $reserva->check_out_date->format('d/m/Y'),
            );
        }

        // 2 y 3 — Estadías. Las abiertas bloquean siempre desde su entrada;
        // las cerradas, solo si su salida real cae dentro del rango pedido.
        $estadias = Stay::query()
            ->where('hotel_id', $hotelId)
            ->whereIn('status', [Stay::STATUS_OPEN, Stay::STATUS_CLOSED])
            ->whereDate('check_in_at', '<', $hasta->toDateString())
            ->where(function ($q) use ($desde) {
                $q->where('status', Stay::STATUS_OPEN)
                    ->orWhere(fn ($cerradas) => $cerradas
                        ->where('status', Stay::STATUS_CLOSED)
                        ->where('check_out_at', '>', $desde->copy()->startOfDay()));
            })
            ->when($ignorarEstadiaId, fn ($q) => $q->where('id', '!=', $ignorarEstadiaId))
            ->with('primaryGuest:id,first_name,last_name')
            ->get();

        foreach ($estadias as $estadia) {
            $fin = $this->finEfectivo($estadia);

            if (! $fin->gt($desde)) {
                continue;
            }

            $motivos[(int) $estadia->room_id][] = sprintf(
                'Estadía %s de %s (entró %s, sale %s)',
                $estadia->fullNumber(),
                $estadia->nombreDelHuesped(),
                $estadia->check_in_at->format('d/m/Y'),
                $fin->format('d/m/Y'),
            );
        }

        // 4 — El estado de la habitación. Mantenimiento y bloqueada pesan más
        // que cualquier fecha.
        $fueraDeServicio = Room::query()
            ->where('hotel_id', $hotelId)
            ->where(fn ($q) => $q
                ->whereIn('status', Room::STATUSES_NO_VENDIBLES)
                ->orWhere('active', false))
            ->get(['id', 'number', 'status', 'active']);

        foreach ($fueraDeServicio as $habitacion) {
            $motivos[(int) $habitacion->id][] = $habitacion->active
                ? 'Habitación en '.strtolower(Room::STATUSES[$habitacion->status] ?? $habitacion->status)
                : 'Habitación inactiva';
        }

        return $motivos;
    }

    /** Los motivos por los que esa habitación no se puede dar en ese rango. */
    public function conflictosDe(
        Room|int $habitacion,
        Carbon|string $desde,
        Carbon|string $hasta,
        ?int $ignorarReservaId = null,
        ?int $ignorarEstadiaId = null,
    ): array {
        $habitacion = $habitacion instanceof Room
            ? $habitacion
            : Room::query()->findOrFail($habitacion);

        $motivos = $this->comprometidas(
            (int) $habitacion->hotel_id,
            $desde,
            $hasta,
            $ignorarReservaId,
            $ignorarEstadiaId,
        );

        return $motivos[(int) $habitacion->id] ?? [];
    }

    public function estaLibre(
        Room|int $habitacion,
        Carbon|string $desde,
        Carbon|string $hasta,
        ?int $ignorarReservaId = null,
        ?int $ignorarEstadiaId = null,
    ): bool {
        return $this->conflictosDe($habitacion, $desde, $hasta, $ignorarReservaId, $ignorarEstadiaId) === [];
    }

    /**
     * Revienta con el motivo si la habitación no se puede dar.
     *
     * El motivo importa: «no está disponible» obliga al recepcionista a
     * revisar a mano para entender qué pasó, teniendo al cliente al frente.
     * «La tiene la reserva R-0012 de Pedro Gómez» se resuelve en el mostrador.
     */
    public function exigirLibre(
        Room|int $habitacion,
        Carbon|string $desde,
        Carbon|string $hasta,
        ?int $ignorarReservaId = null,
        ?int $ignorarEstadiaId = null,
    ): void {
        $conflictos = $this->conflictosDe($habitacion, $desde, $hasta, $ignorarReservaId, $ignorarEstadiaId);

        if ($conflictos === []) {
            return;
        }

        $habitacion = $habitacion instanceof Room
            ? $habitacion
            : Room::query()->findOrFail($habitacion);

        throw new RuntimeException(sprintf(
            'La habitación %s no está disponible en esas fechas: %s',
            $habitacion->number,
            implode(' · ', $conflictos),
        ));
    }

    /**
     * Las habitaciones que sí se pueden dar en ese rango.
     *
     * @return Collection<int, Room>
     */
    public function habitacionesLibres(
        int $hotelId,
        Carbon|string $desde,
        Carbon|string $hasta,
        ?int $roomTypeId = null,
        ?int $ignorarReservaId = null,
        ?int $ignorarEstadiaId = null,
    ): Collection {
        $comprometidas = array_keys($this->comprometidas(
            $hotelId,
            $desde,
            $hasta,
            $ignorarReservaId,
            $ignorarEstadiaId,
        ));

        return Room::query()
            ->where('hotel_id', $hotelId)
            ->vendibles()
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->when($comprometidas !== [], fn ($q) => $q->whereNotIn('id', $comprometidas))
            ->with('roomType:id,name,code,base_rate,max_adults,max_children')
            ->get()
            // Orden natural: la 2 antes de la 10. Un ORDER BY de SQL compara
            // carácter por carácter y pondría la 10 primero.
            ->sortBy(fn (Room $h) => $h->number, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** Cuántas quedan por tipo. Para cotizar por teléfono sin abrir el mapa. */
    public function disponiblesPorTipo(
        int $hotelId,
        Carbon|string $desde,
        Carbon|string $hasta,
    ): array {
        return $this->habitacionesLibres($hotelId, $desde, $hasta)
            ->groupBy('room_type_id')
            ->map(fn (Collection $habitaciones) => [
                'tipo' => $habitaciones->first()->roomType,
                'cantidad' => $habitaciones->count(),
                'habitaciones' => $habitaciones->pluck('number')->all(),
            ])
            ->all();
    }

    /**
     * Noches del rango. Es una resta, pero la resta correcta.
     *
     * Del 8 al 10 son dos noches, no tres: `hasta` es exclusiva.
     */
    public function noches(Carbon|string $desde, Carbon|string $hasta): int
    {
        [$desde, $hasta] = $this->normalizar($desde, $hasta);

        return max(1, (int) $desde->diffInDays($hasta));
    }

    /**
     * Fechas saneadas, o un error claro.
     *
     * Devuelve el inicio del día en ambas: lo que se compara son noches, y
     * dejar que se cuele una hora hace que dos rangos del mismo día parezcan
     * no solaparse.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function normalizar(Carbon|string $desde, Carbon|string $hasta): array
    {
        $desde = ($desde instanceof Carbon ? $desde->copy() : Carbon::parse($desde))->startOfDay();
        $hasta = ($hasta instanceof Carbon ? $hasta->copy() : Carbon::parse($hasta))->startOfDay();

        if (! $hasta->gt($desde)) {
            throw new RuntimeException(
                'La fecha de salida tiene que ser posterior a la de entrada. '
                .'Una estadía de una noche va del día que llega al día siguiente.'
            );
        }

        return [$desde, $hasta];
    }

    /**
     * Hasta cuándo ocupa una estadía, de verdad.
     *
     * Una cerrada ocupó hasta la mañana en que se fue. Una abierta ocupa hasta
     * la salida pactada, y si ya se pasó de esa fecha ocupa igual hasta
     * mañana: el huésped está adentro esta noche aunque en el papel debiera
     * haberse ido. Sin esa segunda parte, recepción vendería hoy una
     * habitación que tiene las maletas puestas.
     */
    protected function finEfectivo(Stay $estadia): Carbon
    {
        if ($estadia->status === Stay::STATUS_CLOSED && $estadia->check_out_at) {
            return $estadia->check_out_at->copy()->startOfDay();
        }

        $pactada = $estadia->expected_check_out
            ? $estadia->expected_check_out->copy()->startOfDay()
            : $estadia->check_in_at->copy()->startOfDay()->addDay();

        $manana = now()->startOfDay()->addDay();

        return $pactada->gt($manana) ? $pactada : $manana;
    }
}
