<?php

namespace App\Services\Parking;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingMembership;
use App\Models\Parking\ParkingSession;
use App\Models\Parking\ParkingSpace;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Engine de sesiones de parqueo. Maneja entrada, salida normal, salida con
 * ticket perdido y cancelacion. Delega el calculo del cobro al RateEngine.
 *
 * El cobro/facturacion se conecta en el commit 5; aqui las sesiones quedan
 * 'closed' con amount listo, paid_amount=0 hasta que se complete el cobro.
 */
class ParkingSessionEngine
{
    public function __construct(
        protected ParkingRateEngine $rates,
        protected ParkingMembershipEngine $memberships,
    ) {}

    /**
     * Registra entrada de un vehiculo. Crea sesion 'active' y, si se asigna
     * espacio (parking_space_id), lo marca como ocupado dentro de una sola
     * transaccion para evitar carreras (dos entradas al mismo slot).
     *
     * @param array $payload  ['parking_lot_id', 'vehicle_type_id', 'parking_space_id'?, 'plate', 'notes', 'entry_at'?]
     */
    public function checkIn(array $payload): ParkingSession
    {
        $lot = ParkingLot::query()
            ->where('id', $payload['parking_lot_id'])
            ->where('active', true)
            ->first();
        if (! $lot) {
            throw new RuntimeException('El parqueadero seleccionado no existe o está inactivo.');
        }

        $plate = $this->normalizePlate($payload['plate'] ?? '');
        if ($plate === '') {
            throw new RuntimeException('La placa es obligatoria.');
        }

        // Una sesion activa con la misma placa en el mismo parqueadero impide
        // crear otra (evita duplicados por escaneo doble).
        $existing = ParkingSession::query()
            ->where('parking_lot_id', $lot->id)
            ->where('plate', $plate)
            ->where('status', ParkingSession::STATUS_ACTIVE)
            ->exists();
        if ($existing) {
            throw new RuntimeException("Ya hay una sesión activa para la placa {$plate} en este parqueadero.");
        }

        $spaceId = $payload['parking_space_id'] ?? null;
        // Detectar cobertura por mensualidad/convenio al momento del checkIn
        // — se queda asociada a la sesion para que el checkOut no cobre.
        $membership = $this->memberships->findCoverageFor($plate, (int) $lot->id);

        return DB::transaction(function () use ($lot, $plate, $payload, $spaceId, $membership) {
            $spaceCode = null;
            if ($spaceId) {
                // Lock pesimista para evitar que dos entradas tomen el mismo espacio.
                $space = ParkingSpace::query()
                    ->where('id', $spaceId)
                    ->where('parking_lot_id', $lot->id)
                    ->lockForUpdate()
                    ->first();
                if (! $space) {
                    throw new RuntimeException('El espacio seleccionado no existe en este parqueadero.');
                }
                if (! $space->isFree()) {
                    throw new RuntimeException("El espacio {$space->code} ya no está libre.");
                }
                $space->update(['status' => ParkingSpace::STATUS_OCCUPIED]);
                $spaceCode = $space->code;
            }

            return ParkingSession::create([
                'company_id' => $lot->company_id,
                'parking_lot_id' => $lot->id,
                'vehicle_type_id' => $payload['vehicle_type_id'] ?? null,
                'parking_space_id' => $spaceId,
                'parking_membership_id' => $membership?->id,
                'space_code' => $spaceCode,
                'plate' => $plate,
                'entry_at' => $payload['entry_at'] ?? now(),
                'status' => ParkingSession::STATUS_ACTIVE,
                'notes' => $payload['notes'] ?? null,
                'entry_photo_path' => $payload['entry_photo_path'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);
        });
    }

    /**
     * Cierra la sesion calculando el cobro a partir de la tarifa activa al
     * momento de la salida. Si exitAt es null, se usa now().
     *
     * $amountOverride: valor que el cajero digito a mano en vez del que
     * calcula la tarifa (descuento, cortesia, tarifa pactada...). Queda una
     * linea en el desglose con lo que la tarifa habria cobrado, para que la
     * diferencia se vea en los reportes. Con valor manual tampoco hace falta
     * tener tarifa: el cajero ya dijo cuanto se cobra.
     */
    public function checkOut(ParkingSession $session, ?Carbon $exitAt = null, ?float $amountOverride = null): ParkingSession
    {
        if (! $session->isActive()) {
            throw new RuntimeException('Esta sesión ya fue cerrada o cancelada.');
        }

        $exitAt = $exitAt ?: now();
        if ($exitAt->lt($session->entry_at)) {
            throw new RuntimeException('La hora de salida no puede ser anterior a la de entrada.');
        }

        // Si la sesion esta cubierta por mensualidad/convenio, no se cobra al
        // cliente: la mensualidad se factura por separado. Se cierra con
        // amount=0 y un breakdown explicativo para que quede trazabilidad.
        $membership = $this->resolveCoverage($session, $exitAt);
        if ($membership) {
            $minutes = max(0, (int) $session->entry_at->diffInMinutes($exitAt));
            DB::transaction(function () use ($session, $exitAt, $minutes, $membership) {
                $session->update([
                    'exit_at' => $exitAt,
                    'status' => ParkingSession::STATUS_CLOSED,
                    'total_minutes' => $minutes,
                    'free_minutes' => $minutes,
                    'charge_minutes' => 0,
                    'amount' => 0,
                    'cap_applied' => false,
                    'breakdown' => [[
                        'label' => 'Cubierto por '.$membership->name,
                        'amount' => 0.0,
                    ]],
                    'closed_by_user_id' => Auth::id(),
                    'closed_at' => now(),
                ]);
                $this->releaseSpace($session);
            });
            return $session->fresh();
        }

        $rate = $this->rates->resolveActiveRate(
            (int) $session->parking_lot_id,
            $session->vehicle_type_id,
            $exitAt,
        );

        if (! $rate && $amountOverride === null) {
            throw new RuntimeException(
                'No hay tarifa activa para este parqueadero y tipo de vehículo. '
                .'Configura una tarifa antes de cobrar.'
            );
        }

        if ($rate) {
            $calc = $this->rates->calculate($rate, $session->entry_at, $exitAt);
        } else {
            $minutes = max(0, (int) $session->entry_at->diffInMinutes($exitAt));
            $calc = [
                'minutes' => $minutes,
                'free_minutes' => 0,
                'charge_minutes' => $minutes,
                'amount' => 0.0,
                'cap_applied' => false,
                'breakdown' => [],
            ];
        }
        $calc = $this->applyOverride($calc, $amountOverride);

        DB::transaction(function () use ($session, $rate, $exitAt, $calc) {
            $session->update([
                'rate_id' => $rate?->id,
                'exit_at' => $exitAt,
                'status' => ParkingSession::STATUS_CLOSED,
                'total_minutes' => $calc['minutes'],
                'free_minutes' => $calc['free_minutes'],
                'charge_minutes' => $calc['charge_minutes'],
                'amount' => $calc['amount'],
                'cap_applied' => $calc['cap_applied'],
                'breakdown' => $calc['breakdown'],
                'closed_by_user_id' => Auth::id(),
                'closed_at' => now(),
            ]);
            $this->releaseSpace($session);
        });

        return $session->fresh();
    }

    /**
     * Cobra ticket perdido. Aplica el monto fijo configurado en la tarifa
     * o, si no hay, una simulacion de 24h.
     */
    public function lostTicket(ParkingSession $session, ?float $amountOverride = null): ParkingSession
    {
        if (! $session->isActive()) {
            throw new RuntimeException('Esta sesión ya fue cerrada o cancelada.');
        }

        $rate = $this->rates->resolveActiveRate(
            (int) $session->parking_lot_id,
            $session->vehicle_type_id,
            now(),
        );

        if (! $rate && $amountOverride === null) {
            throw new RuntimeException(
                'No hay tarifa activa para aplicar el cobro de ticket perdido.'
            );
        }

        $calc = $rate
            ? $this->rates->calculateLostTicket($rate)
            : ['amount' => 0.0, 'breakdown' => []];
        $calc = $this->applyOverride($calc, $amountOverride);

        DB::transaction(function () use ($session, $rate, $calc) {
            $session->update([
                'rate_id' => $rate?->id,
                'exit_at' => now(),
                'status' => ParkingSession::STATUS_LOST_TICKET,
                'amount' => $calc['amount'],
                'cap_applied' => false,
                'breakdown' => $calc['breakdown'],
                'closed_by_user_id' => Auth::id(),
                'closed_at' => now(),
            ]);
            $this->releaseSpace($session);
        });

        return $session->fresh();
    }

    /**
     * Anula una sesion activa (ej. error de registro, vehiculo equivocado).
     * Requiere motivo para auditoria.
     */
    public function cancel(ParkingSession $session, string $reason): ParkingSession
    {
        if (! $session->isActive()) {
            throw new RuntimeException('Solo se pueden anular sesiones activas.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('El motivo de anulación es obligatorio.');
        }

        DB::transaction(function () use ($session, $reason) {
            $session->update([
                'status' => ParkingSession::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'closed_by_user_id' => Auth::id(),
                'closed_at' => now(),
            ]);
            $this->releaseSpace($session);
        });

        return $session->fresh();
    }

    /**
     * Cobro directo: un vehiculo que se cobra sin haber registrado entrada.
     *
     * Pasa en la porteria: el carro ya estaba adentro antes de abrir el
     * turno, o el cliente paga al llegar una tarifa fija, o el operario no
     * alcanzo a registrar la entrada. El cajero digita placa y valor y sale
     * la factura.
     *
     * Se guarda igual como una sesion —cerrada, con entrada y salida en el
     * mismo instante— para que el cobro aparezca en el reporte de sesiones y
     * en el de recaudo como cualquier otro, y para que la factura quede
     * enlazada a una placa.
     *
     * @param  array  $payload  ['parking_lot_id', 'plate', 'amount', 'vehicle_type_id'?, 'notes'?]
     */
    public function directCharge(array $payload): ParkingSession
    {
        $lot = ParkingLot::query()
            ->where('id', $payload['parking_lot_id'] ?? null)
            ->where('active', true)
            ->first();
        if (! $lot) {
            throw new RuntimeException('Selecciona un parqueadero activo.');
        }

        $plate = $this->normalizePlate((string) ($payload['plate'] ?? ''));
        if ($plate === '') {
            throw new RuntimeException('La placa es obligatoria.');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Digita el valor a cobrar.');
        }

        // Si el carro si tiene entrada registrada, lo correcto es cobrarle
        // la salida: si no, la sesion queda abierta y el espacio ocupado.
        $activa = ParkingSession::query()
            ->where('parking_lot_id', $lot->id)
            ->where('plate', $plate)
            ->where('status', ParkingSession::STATUS_ACTIVE)
            ->exists();
        if ($activa) {
            throw new RuntimeException(
                "La placa {$plate} tiene una entrada registrada. Cóbrala desde la salida (digita la placa en el buscador)."
            );
        }

        $now = now();

        return ParkingSession::create([
            'company_id' => $lot->company_id,
            'parking_lot_id' => $lot->id,
            'vehicle_type_id' => $payload['vehicle_type_id'] ?? null,
            'plate' => $plate,
            'entry_at' => $now,
            'exit_at' => $now,
            'status' => ParkingSession::STATUS_CLOSED,
            'total_minutes' => 0,
            'free_minutes' => 0,
            'charge_minutes' => 0,
            'amount' => $amount,
            'cap_applied' => false,
            'breakdown' => [[
                'label' => self::DIRECT_CHARGE_LABEL,
                'amount' => $amount,
            ]],
            'notes' => $payload['notes'] ?? null,
            'created_by_user_id' => Auth::id(),
            'closed_by_user_id' => Auth::id(),
            'closed_at' => $now,
        ]);
    }

    /** Marca en el desglose de las sesiones creadas por directCharge(). */
    public const DIRECT_CHARGE_LABEL = 'Cobro directo (sin registro de entrada)';

    /**
     * Reemplaza el monto calculado por el que digito el cajero y deja en el
     * desglose cuanto era el calculado, para que el ajuste no se pierda.
     */
    protected function applyOverride(array $calc, ?float $override): array
    {
        if ($override === null) {
            return $calc;
        }

        $override = round(max(0, $override), 2);
        $calculado = round((float) ($calc['amount'] ?? 0), 2);

        if ($override === $calculado) {
            return $calc;
        }

        $calc['breakdown'] = array_values($calc['breakdown'] ?? []);
        $calc['breakdown'][] = [
            'label' => 'Valor ajustado por el cajero (calculado $'.number_format($calculado, 0, ',', '.').')',
            'amount' => round($override - $calculado, 2),
        ];
        $calc['amount'] = $override;
        $calc['cap_applied'] = false;

        return $calc;
    }

    /**
     * Libera el espacio asociado a la sesion (si tiene). Se llama desde
     * checkOut, lostTicket y cancel. Idempotente: si el espacio no esta
     * 'occupied' (ej. cambio manual desde admin), no hace nada para no
     * pisar el estado.
     */
    protected function releaseSpace(ParkingSession $session): void
    {
        if (! $session->parking_space_id) return;
        ParkingSpace::query()
            ->where('id', $session->parking_space_id)
            ->where('status', ParkingSpace::STATUS_OCCUPIED)
            ->update(['status' => ParkingSpace::STATUS_FREE]);
    }

    /**
     * Cotiza (sin cobrar) el monto que se cobraria si el vehiculo saliera
     * en este momento. Util para pantalla previa al pago.
     */
    public function quote(ParkingSession $session, ?Carbon $exitAt = null): array
    {
        $exitAt = $exitAt ?: now();

        // Si esta cubierta por mensualidad/convenio, el monto es 0
        $membership = $this->resolveCoverage($session, $exitAt);
        if ($membership) {
            $minutes = max(0, (int) $session->entry_at->diffInMinutes($exitAt));
            return [
                'minutes' => $minutes,
                'free_minutes' => $minutes,
                'charge_minutes' => 0,
                'amount' => 0.0,
                'cap_applied' => false,
                'breakdown' => [[
                    'label' => 'Cubierto por '.$membership->name,
                    'amount' => 0.0,
                ]],
                'rate' => null,
                'membership' => $membership,
            ];
        }

        $rate = $this->rates->resolveActiveRate(
            (int) $session->parking_lot_id,
            $session->vehicle_type_id,
            $exitAt,
        );
        if (! $rate) {
            return [
                'minutes' => (int) $session->entry_at->diffInMinutes($exitAt),
                'amount' => 0.0,
                'breakdown' => [],
                'rate' => null,
                'error' => 'No hay tarifa activa.',
            ];
        }
        return array_merge(
            $this->rates->calculate($rate, $session->entry_at, $exitAt),
            ['rate' => $rate],
        );
    }

    /**
     * Devuelve la mensualidad/convenio que cubre la sesion, o null.
     *
     * La cobertura se sella en el checkIn, pero puede aparecer despues: la
     * mensualidad se creo o se renovo con el vehiculo ya adentro, o la placa
     * no matcheo al entrar. Por eso, si la sesion no la trae, se vuelve a
     * buscar aqui — a la hora de salida y, si no, a la de entrada — y se
     * sella en la sesion para que quote() y checkOut() no se contradigan.
     *
     * Solo sella sesiones activas: una sesion ya cerrada no se reescribe.
     */
    protected function resolveCoverage(ParkingSession $session, ?CarbonInterface $at = null): ?ParkingMembership
    {
        if ($session->parking_membership_id) {
            return $session->relationLoaded('parkingMembership')
                ? $session->parkingMembership
                : $session->parkingMembership()->first();
        }

        if (! $session->isActive() || ! $session->plate) {
            return null;
        }

        $at = $at ?: now();
        $lotId = (int) $session->parking_lot_id;
        $plate = (string) $session->plate;

        $membership = $this->memberships->findCoverageFor($plate, $lotId, $at);

        // Estadia larga: la mensualidad pudo estar vigente al entrar y haber
        // vencido durante la estadia. Se respeta la cobertura de la entrada.
        if (! $membership && $session->entry_at && ! $session->entry_at->isSameDay($at)) {
            $membership = $this->memberships->findCoverageFor($plate, $lotId, $session->entry_at);
        }

        if (! $membership) {
            return null;
        }

        $session->forceFill(['parking_membership_id' => $membership->id])->save();
        $session->setRelation('parkingMembership', $membership);

        return $membership;
    }

    protected function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($plate)));
    }
}
