<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Hotel;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationGroup;
use App\Models\Hotel\RoomType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bloques de reservas: crearlos, ampliarlos, cancelarlos.
 *
 * Lo importante de este archivo es lo que NO hace: no decide disponibilidad.
 * Cada habitación del bloque se aparta llamando a `HotelReservationEngine`,
 * que a su vez pregunta a `HotelAvailability`. Un grupo que se saltara ese
 * paso sería la forma más rápida de meter un sobrecupo de diez habitaciones
 * de un golpe.
 *
 * Y por eso crear un grupo **puede quedar incompleto**: si se piden diez
 * dobles y solo hay siete, se crean siete y se dice cuántas faltaron. Es
 * preferible a reventar sin crear nada —el recepcionista tiene el cliente al
 * teléfono— y mucho mejor que prometer diez que no existen.
 */
class HotelGroupEngine
{
    public function __construct(
        protected HotelAvailability $disponibilidad,
        protected HotelReservationEngine $reservas,
    ) {}

    /**
     * Crea el bloque y sus habitaciones.
     *
     * @param  array{hotel_id: int, name: string, check_in_date: string, check_out_date: string,
     *               habitaciones: list<array{room_type_id: int, cantidad: int, adults?: int,
     *                                        children?: int, rate_per_night?: float|null}>,
     *               third_party_id?: int|null, contact_name?: string|null,
     *               contact_phone?: string|null, contact_email?: string|null,
     *               meal_plan_id?: int|null, source?: string, bill_together?: bool,
     *               notes?: string|null}  $datos
     * @return array{grupo: ReservationGroup, creadas: int, faltaron: list<string>}
     */
    public function crear(array $datos): array
    {
        $hotel = Hotel::query()
            ->where('id', $datos['hotel_id'] ?? 0)
            ->where('active', true)
            ->first();

        if (! $hotel) {
            throw new RuntimeException('El hotel seleccionado no existe o está inactivo.');
        }

        $nombre = trim((string) ($datos['name'] ?? ''));

        if ($nombre === '') {
            throw new RuntimeException(
                'El grupo necesita un nombre: «Boda Gómez», «Congreso de odontología». '
                .'Es por donde se busca el día de la llegada.'
            );
        }

        [$entrada, $salida] = $this->disponibilidad->normalizar(
            $datos['check_in_date'] ?? '',
            $datos['check_out_date'] ?? '',
        );

        $bloques = array_values(array_filter(
            $datos['habitaciones'] ?? [],
            fn ($b) => (int) ($b['cantidad'] ?? 0) > 0,
        ));

        if ($bloques === []) {
            throw new RuntimeException('Indica al menos un tipo de habitación y cuántas se apartan.');
        }

        $grupo = DB::transaction(function () use ($hotel, $nombre, $entrada, $salida, $datos) {
            $grupo = ReservationGroup::create([
                'company_id' => $hotel->company_id,
                'hotel_id' => $hotel->id,
                'name' => $nombre,
                'third_party_id' => $datos['third_party_id'] ?? null,
                'contact_name' => $datos['contact_name'] ?? null,
                'contact_phone' => $datos['contact_phone'] ?? null,
                'contact_email' => $datos['contact_email'] ?? null,
                'check_in_date' => $entrada->toDateString(),
                'check_out_date' => $salida->toDateString(),
                'source' => array_key_exists((string) ($datos['source'] ?? ''), Reservation::SOURCES)
                    ? $datos['source']
                    : 'agency',
                'status' => ReservationGroup::STATUS_OPEN,
                'bill_together' => (bool) ($datos['bill_together'] ?? true),
                'notes' => $datos['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $grupo->forceFill(['code' => 'G-'.str_pad((string) $grupo->id, 6, '0', STR_PAD_LEFT)])->save();

            return $grupo;
        });

        $resultado = $this->apartarHabitaciones($grupo, $bloques, $datos);

        if ($resultado['creadas'] === 0) {
            // Un grupo sin una sola habitación no sirve para nada y encima
            // ensucia el listado. Se deshace.
            $grupo->forceDelete();

            throw new RuntimeException(
                'No quedó ninguna habitación libre para ese bloque: '
                .implode(' · ', $resultado['faltaron'])
            );
        }

        return array_merge(['grupo' => $grupo->fresh()], $resultado);
    }

    /**
     * Agrega más habitaciones a un bloque que ya existe.
     *
     * @param  list<array{room_type_id: int, cantidad: int, adults?: int, children?: int,
     *                    rate_per_night?: float|null}>  $bloques
     * @return array{creadas: int, faltaron: list<string>}
     */
    public function agregarHabitaciones(ReservationGroup $grupo, array $bloques, array $datos = []): array
    {
        if (! $grupo->estaAbierto()) {
            throw new RuntimeException(
                'Este grupo está '.strtolower(ReservationGroup::STATUSES[$grupo->status] ?? $grupo->status)
                .': no se le pueden agregar habitaciones.'
            );
        }

        return $this->apartarHabitaciones($grupo, $bloques, $datos);
    }

    /**
     * Aparta una por una, cada cual con su propia comprobación.
     *
     * Las habitaciones del mismo tipo se van ocupando a medida que se crean,
     * así que la disponibilidad se recalcula en cada vuelta: pedir cinco
     * dobles no reserva cinco veces la misma.
     *
     * @param  list<array<string, mixed>>  $bloques
     * @param  array<string, mixed>  $datos
     * @return array{creadas: int, faltaron: list<string>}
     */
    protected function apartarHabitaciones(ReservationGroup $grupo, array $bloques, array $datos): array
    {
        $creadas = 0;
        $faltaron = [];

        foreach ($bloques as $bloque) {
            $tipo = RoomType::query()
                ->where('hotel_id', $grupo->hotel_id)
                ->find($bloque['room_type_id'] ?? 0);

            if (! $tipo) {
                continue;
            }

            $cantidad = max(1, (int) $bloque['cantidad']);
            $sinCupo = 0;

            for ($i = 1; $i <= $cantidad; $i++) {
                $libres = $this->disponibilidad->habitacionesLibres(
                    (int) $grupo->hotel_id,
                    $grupo->check_in_date,
                    $grupo->check_out_date,
                    (int) $tipo->id,
                );

                if ($libres->isEmpty()) {
                    $sinCupo = $cantidad - $i + 1;
                    break;
                }

                try {
                    $reserva = $this->reservas->crear([
                        'hotel_id' => $grupo->hotel_id,
                        'room_id' => $libres->first()->id,
                        'room_type_id' => $tipo->id,
                        'meal_plan_id' => $datos['meal_plan_id'] ?? null,
                        'check_in_date' => $grupo->check_in_date->toDateString(),
                        'check_out_date' => $grupo->check_out_date->toDateString(),
                        // Cada habitación del bloque lleva el nombre del grupo
                        // más su número: el día de la llegada el huésped dice
                        // «vengo con la boda Gómez», no su propio apellido.
                        'guest_name' => $grupo->name.' · hab. '.$i,
                        'guest_phone' => $grupo->contact_phone,
                        'guest_email' => $grupo->contact_email,
                        'third_party_id' => $grupo->third_party_id,
                        'adults' => (int) ($bloque['adults'] ?? 2),
                        'children' => (int) ($bloque['children'] ?? 0),
                        'rate_per_night' => $bloque['rate_per_night'] ?? null,
                        'source' => $grupo->source,
                        'notes' => $datos['notes'] ?? null,
                    ]);

                    $reserva->forceFill(['group_id' => $grupo->id])->save();
                    $creadas++;
                } catch (\Throwable $e) {
                    // Si una no entra, el resto del bloque sí puede: el
                    // recepcionista prefiere siete habitaciones y un aviso
                    // claro antes que un error y cero.
                    $sinCupo = $cantidad - $i + 1;
                    break;
                }
            }

            if ($sinCupo > 0) {
                $faltaron[] = sprintf('faltaron %d de tipo %s', $sinCupo, $tipo->name);
            }
        }

        return ['creadas' => $creadas, 'faltaron' => $faltaron];
    }

    /** Confirma de una vez todas las reservas por confirmar del bloque. */
    public function confirmar(ReservationGroup $grupo): int
    {
        $confirmadas = 0;

        foreach ($grupo->reservations()->where('status', Reservation::STATUS_PENDING)->get() as $reserva) {
            $this->reservas->confirmar($reserva);
            $confirmadas++;
        }

        return $confirmadas;
    }

    /**
     * Cancela el bloque entero.
     *
     * Las que ya hicieron check-in no se tocan: hay gente adentro, y lo que
     * corresponde ahí es un check-out, no una cancelación.
     *
     * @return array{canceladas: int, alojados: int}
     */
    public function cancelar(ReservationGroup $grupo, string $motivo): array
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('El motivo de la cancelación es obligatorio.');
        }

        $canceladas = 0;
        $alojados = 0;

        foreach ($grupo->reservations as $reserva) {
            if ($reserva->status === Reservation::STATUS_CHECKED_IN) {
                $alojados++;

                continue;
            }

            if (! $reserva->estaViva()) {
                continue;
            }

            $this->reservas->cancelar($reserva, $motivo);
            $canceladas++;
        }

        $grupo->update([
            'status' => ReservationGroup::STATUS_CANCELLED,
            'cancel_reason' => $motivo,
            'closed_at' => now(),
        ]);

        return ['canceladas' => $canceladas, 'alojados' => $alojados];
    }
}
