<?php

namespace App\Services\Hotel;

use App\Models\Hotel\RateSeason;
use App\Models\Hotel\RoomType;
use Illuminate\Support\Carbon;

/**
 * Cuánto vale una noche.
 *
 * La tarifa base vive en el tipo de habitación y eso resuelve el día normal.
 * Encima van las temporadas: si la noche cae dentro de una, manda la
 * temporada.
 *
 * **Cada noche se cotiza por separado.** Una estadía del 28 de diciembre al 3
 * de enero cruza dos temporadas y cada noche cuesta lo suyo; promediar sería
 * cobrarle de menos al hotel unos días y de más al huésped otros. El folio ya
 * guarda un renglón por noche, así que esto encaja sin inventar nada: cada
 * renglón nace con el precio de su fecha.
 *
 * Existe este servicio y no un método en RoomType porque el tipo no sabe de
 * fechas, y porque la misma pregunta la hacen cuatro sitios —cotizar una
 * reserva, proponer la tarifa de un check-in, cobrar cada noche del folio y
 * pintar el calendario—. Escrita cuatro veces, un día el calendario muestra un
 * precio y el folio cobra otro.
 */
class HotelTarifas
{
    /**
     * La temporada que manda esa noche, o null si es tarifa normal.
     *
     * Se consulta por noche, así que se recuerda lo ya resuelto: una
     * cotización de treinta noches preguntaría treinta veces a la base por lo
     * mismo.
     *
     * @var array<string, RateSeason|null>
     */
    private array $recordadas = [];

    public function temporadaDe(?RoomType $tipo, Carbon|string $noche): ?RateSeason
    {
        if (! $tipo) {
            return null;
        }

        $fecha = ($noche instanceof Carbon ? $noche->copy() : Carbon::parse($noche))->toDateString();
        $llave = $tipo->hotel_id.'|'.$tipo->id.'|'.$fecha;

        if (array_key_exists($llave, $this->recordadas)) {
            return $this->recordadas[$llave];
        }

        return $this->recordadas[$llave] = RateSeason::query()
            ->where('hotel_id', $tipo->hotel_id)
            ->queCubren($fecha, (int) $tipo->id)
            ->first();
    }

    /**
     * El precio al público de esa noche, para esa cantidad de adultos.
     *
     * Si la temporada no dice qué cobrar por persona adicional, se usa el del
     * tipo: lo que la temporada cambia normalmente es la tarifa, no la
     * política de acompañantes.
     */
    public function paraNoche(?RoomType $tipo, Carbon|string $noche, int $adultos = 1): float
    {
        if (! $tipo) {
            return 0.0;
        }

        $temporada = $this->temporadaDe($tipo, $noche);

        if (! $temporada) {
            return $tipo->tarifaPara($adultos);
        }

        $extras = max(0, $adultos - max(1, (int) $tipo->max_adults));

        $porPersona = $temporada->extra_person_rate !== null
            ? (float) $temporada->extra_person_rate
            : (float) $tipo->extra_person_rate;

        return round((float) $temporada->rate + $extras * $porPersona, 2);
    }

    /**
     * Lo que costaría una estadía, noche por noche.
     *
     * El detalle importa tanto como el total: es lo que se le muestra al
     * cliente cuando pregunta «¿por qué me sale más caro si son las mismas
     * noches?», y la respuesta es que dos de ellas son 31 de diciembre.
     *
     * @return array{
     *   noches: int, total: float, promedio: float,
     *   detalle: list<array{fecha: string, tarifa: float, temporada: ?string}>,
     *   temporadas: list<string>,
     * }
     */
    public function cotizar(
        ?RoomType $tipo,
        Carbon|string $desde,
        Carbon|string $hasta,
        int $adultos = 1,
    ): array {
        [$desde, $hasta] = app(HotelAvailability::class)->normalizar($desde, $hasta);

        $detalle = [];
        $temporadas = [];
        $total = 0.0;

        $noche = $desde->copy();

        while ($noche->lt($hasta)) {
            $temporada = $this->temporadaDe($tipo, $noche);
            $tarifa = $this->paraNoche($tipo, $noche, $adultos);

            $detalle[] = [
                'fecha' => $noche->toDateString(),
                'tarifa' => $tarifa,
                'temporada' => $temporada?->name,
            ];

            if ($temporada && ! in_array($temporada->name, $temporadas, true)) {
                $temporadas[] = $temporada->name;
            }

            $total += $tarifa;
            $noche->addDay();
        }

        $noches = count($detalle);

        return [
            'noches' => $noches,
            'total' => round($total, 2),
            // El promedio es solo para mostrar «$X por noche» de un vistazo.
            // Lo que se cobra es el detalle.
            'promedio' => $noches > 0 ? round($total / $noches, 2) : 0.0,
            'detalle' => $detalle,
            'temporadas' => $temporadas,
        ];
    }

    /** Para las pruebas, que crean y borran temporadas entre casos. */
    public function olvidarCache(): void
    {
        $this->recordadas = [];
    }
}
