<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Hotel;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Crea habitaciones en lote.
 *
 * Un hotel de treinta habitaciones no se configura una por una: la numeración
 * es casi siempre un rango por piso —101 a 112, 201 a 212— y escribirlas a
 * mano es media hora de trabajo y un par de números repetidos.
 *
 * Omite las que ya existen en vez de fallar: el caso normal es ampliar un
 * rango ya creado («ahora el piso 1 llega hasta la 115»), y que eso reviente
 * a mitad de camino dejaría el lote a medias.
 */
class HotelRoomBulkCreator
{
    /**
     * @param  array{hotel_id: int, room_type_id: int, zone_id?: int|null,
     *               prefix?: string, from: int|string, to: int|string, step?: int,
     *               padding?: int, floor?: string|null, max_occupancy?: int|null,
     *               status?: string}  $datos
     * @return array{creadas: int, omitidas: list<string>, reactivadas: int}
     */
    public function create(array $datos): array
    {
        $hotel = Hotel::query()->find($datos['hotel_id'] ?? 0);

        if (! $hotel) {
            throw new InvalidArgumentException('El hotel seleccionado no existe.');
        }

        $tipo = RoomType::query()
            ->where('hotel_id', $hotel->id)
            ->find($datos['room_type_id'] ?? 0);

        if (! $tipo) {
            throw new InvalidArgumentException('El tipo de habitación no existe en este hotel.');
        }

        $numeros = self::construirNumeros($datos);

        if ($numeros === []) {
            throw new InvalidArgumentException('El rango no genera ninguna habitación.');
        }

        return DB::transaction(function () use ($hotel, $tipo, $numeros, $datos) {
            $creadas = 0;
            $reactivadas = 0;
            $omitidas = [];

            foreach ($numeros as $numero) {
                // Sin filtrar borrados: la llave única del esquema las cuenta
                // igual, así que una habitación en papelera se reactiva en vez
                // de reventar con un error de duplicado.
                $existente = Room::withTrashed()
                    ->where('hotel_id', $hotel->id)
                    ->where('number', $numero)
                    ->first();

                if ($existente) {
                    if ($existente->trashed()) {
                        $existente->restore();
                        $reactivadas++;
                    } else {
                        $omitidas[] = $numero;
                    }

                    continue;
                }

                Room::create([
                    'company_id' => $hotel->company_id,
                    'hotel_id' => $hotel->id,
                    'zone_id' => $datos['zone_id'] ?? null,
                    'room_type_id' => $tipo->id,
                    'number' => $numero,
                    'floor' => $datos['floor'] ?? null,
                    'status' => $datos['status'] ?? Room::STATUS_AVAILABLE,
                    'max_occupancy' => (int) ($datos['max_occupancy'] ?: $tipo->capacidad()),
                    'active' => true,
                ]);

                $creadas++;
            }

            return ['creadas' => $creadas, 'omitidas' => $omitidas, 'reactivadas' => $reactivadas];
        });
    }

    /**
     * Los números del rango, ya formateados.
     *
     * Pública y estática para que la pantalla pueda mostrar la vista previa
     * con exactamente los mismos números que se van a crear. Una vista previa
     * calculada aparte es una vista previa que algún día miente.
     *
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    public static function construirNumeros(array $datos): array
    {
        $desde = (int) ($datos['from'] ?? 0);
        $hasta = (int) ($datos['to'] ?? 0);
        $salto = max(1, (int) ($datos['step'] ?? 1));
        $relleno = max(0, (int) ($datos['padding'] ?? 0));
        $prefijo = trim((string) ($datos['prefix'] ?? ''));

        if ($hasta < $desde) {
            throw new InvalidArgumentException('El número final tiene que ser mayor o igual al inicial.');
        }

        if (($hasta - $desde) / $salto > 500) {
            throw new InvalidArgumentException(
                'El rango genera más de 500 habitaciones. Divídelo por pisos para no crear '
                .'de golpe algo que después hay que borrar a mano.'
            );
        }

        $numeros = [];

        for ($i = $desde; $i <= $hasta; $i += $salto) {
            $numeros[] = $prefijo.($relleno > 0 ? str_pad((string) $i, $relleno, '0', STR_PAD_LEFT) : (string) $i);
        }

        return $numeros;
    }
}
