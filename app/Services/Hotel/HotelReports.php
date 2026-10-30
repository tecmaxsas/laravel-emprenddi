<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Guest;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Los números del hotel.
 *
 * Vive aquí y no en las pantallas porque cada reporte se consulta dos veces:
 * una para mostrarlo y otra para exportarlo a Excel. Escrito en los dos
 * sitios, un día el Excel dice una cifra distinta a la de la pantalla y no
 * hay forma de saber cuál está bien. Es exactamente lo que pasa cuando una
 * regla se copia.
 *
 * LAS TRES MÉTRICAS DE LA HOTELERÍA, que no son el total facturado:
 *
 *  - **Ocupación** = noches vendidas / noches disponibles. Cuánto del hotel
 *    se usó.
 *  - **ADR** (tarifa media diaria) = ingreso de alojamiento / noches
 *    vendidas. A cuánto se vendió lo que se vendió.
 *  - **RevPAR** = ingreso de alojamiento / noches disponibles. La que de
 *    verdad manda, porque mezcla las dos: un hotel con ADR altísimo y 20% de
 *    ocupación gana menos que uno con ADR medio y 90%, y mirando solo el ADR
 *    nadie lo nota.
 *
 * El ingreso de alojamiento sale de los renglones de noche del folio, no de
 * las facturas. Es la única fuente que sabe **de qué noche** era cada peso:
 * una estadía de cinco noches se factura un solo día, y contarla ahí haría
 * que un martes apareciera con el ingreso de toda la semana.
 */
class HotelReports
{
    /**
     * Ocupación, ADR y RevPAR día por día.
     *
     * @param  array{from?: string, to?: string, hotel_id?: int|null}  $filtros
     * @return array{filas: Collection, totales: array<string, float|int>, disponibles: int}
     */
    public function ocupacion(array $filtros): array
    {
        [$desde, $hasta] = $this->rango($filtros);
        $hotelId = $filtros['hotel_id'] ?? null;

        // Habitaciones del inventario vendible, HOY.
        //
        // Es una aproximación y conviene decirlo: el sistema no guarda cuántas
        // habitaciones tenía el hotel en marzo, así que un hotel que amplió un
        // piso verá la ocupación de los meses viejos calculada contra el
        // inventario de ahora. Guardar un histórico de inventario por día es
        // mucha máquina para una cifra que casi nadie mira hacia atrás.
        $disponibles = Room::query()
            ->where('company_id', $this->empresa())
            ->when($hotelId, fn ($q) => $q->where('hotel_id', $hotelId))
            ->vendibles()
            ->count();

        $noches = StayCharge::query()
            ->select(
                'hotel_stay_charges.night_date as dia',
                DB::raw('COUNT(*) as noches_vendidas'),
                DB::raw('COALESCE(SUM(hotel_stay_charges.total), 0) as alojamiento'),
            )
            ->join('hotel_stays', 'hotel_stays.id', '=', 'hotel_stay_charges.stay_id')
            ->where('hotel_stay_charges.company_id', $this->empresa())
            ->where('hotel_stay_charges.kind', StayCharge::KIND_ROOM)
            ->whereNull('hotel_stay_charges.deleted_at')
            // Una estadía anulada no vendió nada: sus noches no cuentan.
            ->where('hotel_stays.status', '!=', Stay::STATUS_CANCELLED)
            ->when($hotelId, fn ($q) => $q->where('hotel_stays.hotel_id', $hotelId))
            ->whereBetween('hotel_stay_charges.night_date', [$desde, $hasta])
            ->groupBy('hotel_stay_charges.night_date')
            ->get()
            ->keyBy(fn ($fila) => Carbon::parse($fila->dia)->toDateString());

        $consumos = StayCharge::query()
            ->select(
                'hotel_stay_charges.charge_date as dia',
                DB::raw('COALESCE(SUM(hotel_stay_charges.total), 0) as consumos'),
            )
            ->join('hotel_stays', 'hotel_stays.id', '=', 'hotel_stay_charges.stay_id')
            ->where('hotel_stay_charges.company_id', $this->empresa())
            ->whereIn('hotel_stay_charges.kind', [
                StayCharge::KIND_PRODUCT,
                StayCharge::KIND_SERVICE,
                // La alimentación es ingreso del hotel igual que el minibar:
                // sin esto quedaba invisible en el reporte.
                StayCharge::KIND_MEAL,
            ])
            ->whereNull('hotel_stay_charges.deleted_at')
            ->where('hotel_stays.status', '!=', Stay::STATUS_CANCELLED)
            ->when($hotelId, fn ($q) => $q->where('hotel_stays.hotel_id', $hotelId))
            ->whereBetween('hotel_stay_charges.charge_date', [$desde, $hasta])
            ->groupBy('hotel_stay_charges.charge_date')
            ->get()
            ->keyBy(fn ($fila) => Carbon::parse($fila->dia)->toDateString());

        // Se recorre el calendario completo, no solo los días con movimiento:
        // un día con cero ocupación es un dato, y si falta la fila el promedio
        // del mes sale inflado.
        $filas = collect();
        $dia = Carbon::parse($desde);
        $fin = Carbon::parse($hasta);

        while ($dia->lte($fin)) {
            $clave = $dia->toDateString();
            $vendidas = (int) ($noches->get($clave)?->noches_vendidas ?? 0);
            $alojamiento = (float) ($noches->get($clave)?->alojamiento ?? 0);
            $consumo = (float) ($consumos->get($clave)?->consumos ?? 0);

            $filas->push([
                'dia' => $clave,
                'disponibles' => $disponibles,
                'vendidas' => $vendidas,
                'ocupacion' => $disponibles > 0 ? round($vendidas / $disponibles * 100, 1) : 0.0,
                'alojamiento' => round($alojamiento, 2),
                'adr' => $vendidas > 0 ? round($alojamiento / $vendidas, 2) : 0.0,
                'revpar' => $disponibles > 0 ? round($alojamiento / $disponibles, 2) : 0.0,
                'consumos' => round($consumo, 2),
                'total' => round($alojamiento + $consumo, 2),
            ]);

            $dia->addDay();
        }

        $dias = max(1, $filas->count());
        $nochesDisponibles = $disponibles * $dias;
        $vendidasTotal = (int) $filas->sum('vendidas');
        $alojamientoTotal = (float) $filas->sum('alojamiento');

        return [
            'filas' => $filas,
            'disponibles' => $disponibles,
            'totales' => [
                'dias' => $dias,
                'noches_disponibles' => $nochesDisponibles,
                'vendidas' => $vendidasTotal,
                'ocupacion' => $nochesDisponibles > 0 ? round($vendidasTotal / $nochesDisponibles * 100, 1) : 0.0,
                'alojamiento' => round($alojamientoTotal, 2),
                'consumos' => round((float) $filas->sum('consumos'), 2),
                'total' => round((float) $filas->sum('total'), 2),
                'adr' => $vendidasTotal > 0 ? round($alojamientoTotal / $vendidasTotal, 2) : 0.0,
                'revpar' => $nochesDisponibles > 0 ? round($alojamientoTotal / $nochesDisponibles, 2) : 0.0,
            ],
        ];
    }

    /**
     * Pronóstico: cuánto hay vendido de aquí en adelante.
     *
     * Todos los demás reportes miran hacia atrás. Este es el único que mira
     * hacia adelante, y es el que un gerente abre el lunes por la mañana: si
     * el fin de semana viene en 40 %, todavía hay tiempo de hacer algo. Si se
     * entera el lunes siguiente, ya no.
     *
     * Sale del mismo `calendario()` que pinta la rejilla de recepción, así que
     * lo que dice el pronóstico y lo que se ve en el calendario no pueden
     * discrepar.
     *
     * Cuenta estadías abiertas y reservas vivas. Las estadías ya cerradas no
     * entran: esa habitación vuelve a estar en venta.
     *
     * @param  array{hotel_id?: int|null, dias?: int, desde?: string|null}  $filtros
     * @return array{filas: Collection, totales: array<string, float|int>, vendibles: int}
     */
    public function pronostico(array $filtros): array
    {
        $dias = max(1, min(60, (int) ($filtros['dias'] ?? 30)));
        $desde = $filtros['desde'] ?? now()->toDateString();
        $hotelId = $filtros['hotel_id'] ?? null;

        if (! $hotelId) {
            $hotelId = Hotel::query()
                ->where('company_id', $this->empresa())
                ->where('active', true)
                ->orderBy('id')
                ->value('id');
        }

        if (! $hotelId) {
            return ['filas' => collect(), 'totales' => [], 'vendibles' => 0];
        }

        $calendario = app(HotelAvailability::class)->calendario($hotelId, $desde, $dias);

        $vendibles = $calendario['habitaciones']->filter(fn (Room $h) => $h->sePuedeVender())->count();
        $tarifas = app(HotelTarifas::class);

        $filas = collect();

        foreach ($calendario['dias'] as $dia) {
            $alojados = 0;
            $reservados = 0;
            $ingreso = 0.0;

            foreach ($calendario['habitaciones'] as $habitacion) {
                $celda = $calendario['ocupacion'][$habitacion->id][$dia] ?? null;

                if (! $celda) {
                    continue;
                }

                if ($celda['tipo'] === 'estadia') {
                    $alojados++;
                } elseif ($celda['tipo'] === 'reserva') {
                    $reservados++;
                } else {
                    continue;
                }

                // Lo que se espera cobrar esa noche, a la tarifa que
                // corresponda a esa fecha. No es lo facturado —todavía no
                // existe— sino lo comprometido.
                $ingreso += $tarifas->paraNoche($habitacion->roomType, $dia);
            }

            $vendidas = $alojados + $reservados;
            $fecha = Carbon::parse($dia);

            $filas->push([
                'dia' => $dia,
                'finde' => $fecha->isFriday() || $fecha->isSaturday(),
                'vendibles' => $vendibles,
                'alojados' => $alojados,
                'reservados' => $reservados,
                'vendidas' => $vendidas,
                'libres' => max(0, $vendibles - $vendidas),
                'ocupacion' => $vendibles > 0 ? round($vendidas / $vendibles * 100, 1) : 0.0,
                'ingreso' => round($ingreso, 2),
            ]);
        }

        $nochesDisponibles = $vendibles * $filas->count();
        $vendidasTotal = (int) $filas->sum('vendidas');
        $ingresoTotal = (float) $filas->sum('ingreso');

        return [
            'filas' => $filas,
            'vendibles' => $vendibles,
            'totales' => [
                'dias' => $filas->count(),
                'noches_disponibles' => $nochesDisponibles,
                'vendidas' => $vendidasTotal,
                'libres' => max(0, $nochesDisponibles - $vendidasTotal),
                'ocupacion' => $nochesDisponibles > 0 ? round($vendidasTotal / $nochesDisponibles * 100, 1) : 0.0,
                'ingreso' => round($ingresoTotal, 2),
                'adr' => $vendidasTotal > 0 ? round($ingresoTotal / $vendidasTotal, 2) : 0.0,
                'revpar' => $nochesDisponibles > 0 ? round($ingresoTotal / $nochesDisponibles, 2) : 0.0,
            ],
        ];
    }

    /**
     * Las estadías del período, una por fila.
     *
     * Es el detalle que respalda el reporte de ocupación: cuando el dueño
     * pregunta «¿y ese día de qué fue?», esto lo contesta.
     *
     * @param  array{from?: string, to?: string, hotel_id?: int|null, status?: string|null}  $filtros
     * @return Collection<int, Stay>
     */
    public function estadias(array $filtros): Collection
    {
        [$desde, $hasta] = $this->rango($filtros);

        return Stay::query()
            ->where('company_id', $this->empresa())
            ->when($filtros['hotel_id'] ?? null, fn ($q, $id) => $q->where('hotel_id', $id))
            ->when($filtros['status'] ?? null, fn ($q, $estado) => $q->where('status', $estado))
            ->whereDate('check_in_at', '>=', $desde)
            ->whereDate('check_in_at', '<=', $hasta)
            ->with([
                'room:id,number',
                'roomType:id,name',
                'primaryGuest:id,first_name,last_name,document_type,document_number,nationality',
                'customer:id,name,document_number',
                'invoice:id,prefix,number',
            ])
            ->orderByDesc('check_in_at')
            ->get();
    }

    /**
     * Quiénes se alojaron en el período.
     *
     * Con las columnas del registro hotelero —documento, nacionalidad,
     * procedencia, destino, motivo— porque es lo que hay que poder entregar
     * si una autoridad lo pide, y lo que se necesita para reportar a
     * Migración los huéspedes extranjeros.
     *
     * @param  array{from?: string, to?: string, hotel_id?: int|null, solo_extranjeros?: bool}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    public function huespedes(array $filtros): Collection
    {
        [$desde, $hasta] = $this->rango($filtros);

        $estadias = Stay::query()
            ->where('company_id', $this->empresa())
            ->when($filtros['hotel_id'] ?? null, fn ($q, $id) => $q->where('hotel_id', $id))
            ->where('status', '!=', Stay::STATUS_CANCELLED)
            ->whereDate('check_in_at', '>=', $desde)
            ->whereDate('check_in_at', '<=', $hasta)
            ->with(['room:id,number', 'guests'])
            ->orderByDesc('check_in_at')
            ->get();

        $soloExtranjeros = (bool) ($filtros['solo_extranjeros'] ?? false);
        $filas = collect();

        foreach ($estadias as $estadia) {
            foreach ($estadia->guests as $huesped) {
                if ($soloExtranjeros && ! $huesped->esExtranjero()) {
                    continue;
                }

                $filas->push([
                    'folio' => $estadia->fullNumber(),
                    'habitacion' => $estadia->room?->number ?? '—',
                    'entrada' => $estadia->check_in_at,
                    'salida' => $estadia->check_out_at ?: $estadia->expected_check_out,
                    'noches' => (int) $estadia->nights,
                    'titular' => (bool) $huesped->pivot->is_primary,
                    'nombre' => $huesped->nombreCompleto(),
                    'documento' => $huesped->documento(),
                    'nacimiento' => $huesped->birth_date,
                    'nacionalidad' => $huesped->nationality,
                    'extranjero' => $huesped->esExtranjero(),
                    'procedencia' => $huesped->origin,
                    'destino' => $huesped->destination,
                    'motivo' => $huesped->travel_purpose
                        ? (Guest::PURPOSES[$huesped->travel_purpose] ?? $huesped->travel_purpose)
                        : null,
                    'telefono' => $huesped->phone,
                ]);
            }
        }

        return $filas;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{0: string, 1: string}
     */
    protected function rango(array $filtros): array
    {
        $desde = $filtros['from'] ?? now()->startOfMonth()->toDateString();
        $hasta = $filtros['to'] ?? now()->endOfMonth()->toDateString();

        try {
            $desde = Carbon::parse($desde)->toDateString();
            $hasta = Carbon::parse($hasta)->toDateString();
        } catch (\Throwable) {
            $desde = now()->startOfMonth()->toDateString();
            $hasta = now()->endOfMonth()->toDateString();
        }

        // Al revés no se revienta: se intercambian. El usuario quiso un rango.
        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$desde, $hasta];
    }

    protected function empresa(): ?int
    {
        return Auth::user()?->company_id;
    }
}
