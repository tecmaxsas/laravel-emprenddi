<?php

namespace App\Services\Parking;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
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
    ) {}

    /**
     * Registra entrada de un vehiculo. Crea sesion 'active'.
     *
     * @param array $payload  ['parking_lot_id', 'vehicle_type_id', 'plate', 'notes', 'entry_at'?]
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

        return ParkingSession::create([
            'company_id' => $lot->company_id,
            'parking_lot_id' => $lot->id,
            'vehicle_type_id' => $payload['vehicle_type_id'] ?? null,
            'plate' => $plate,
            'entry_at' => $payload['entry_at'] ?? now(),
            'status' => ParkingSession::STATUS_ACTIVE,
            'notes' => $payload['notes'] ?? null,
            'created_by_user_id' => Auth::id(),
        ]);
    }

    /**
     * Cierra la sesion calculando el cobro a partir de la tarifa activa al
     * momento de la salida. Si exitAt es null, se usa now().
     */
    public function checkOut(ParkingSession $session, ?Carbon $exitAt = null): ParkingSession
    {
        if (! $session->isActive()) {
            throw new RuntimeException('Esta sesión ya fue cerrada o cancelada.');
        }

        $exitAt = $exitAt ?: now();
        if ($exitAt->lt($session->entry_at)) {
            throw new RuntimeException('La hora de salida no puede ser anterior a la de entrada.');
        }

        $rate = $this->rates->resolveActiveRate(
            (int) $session->parking_lot_id,
            $session->vehicle_type_id,
            $exitAt,
        );

        if (! $rate) {
            throw new RuntimeException(
                'No hay tarifa activa para este parqueadero y tipo de vehículo. '
                .'Configura una tarifa antes de cobrar.'
            );
        }

        $calc = $this->rates->calculate($rate, $session->entry_at, $exitAt);

        $session->update([
            'rate_id' => $rate->id,
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

        return $session->fresh();
    }

    /**
     * Cobra ticket perdido. Aplica el monto fijo configurado en la tarifa
     * o, si no hay, una simulacion de 24h.
     */
    public function lostTicket(ParkingSession $session): ParkingSession
    {
        if (! $session->isActive()) {
            throw new RuntimeException('Esta sesión ya fue cerrada o cancelada.');
        }

        $rate = $this->rates->resolveActiveRate(
            (int) $session->parking_lot_id,
            $session->vehicle_type_id,
            now(),
        );

        if (! $rate) {
            throw new RuntimeException(
                'No hay tarifa activa para aplicar el cobro de ticket perdido.'
            );
        }

        $calc = $this->rates->calculateLostTicket($rate);

        $session->update([
            'rate_id' => $rate->id,
            'exit_at' => now(),
            'status' => ParkingSession::STATUS_LOST_TICKET,
            'amount' => $calc['amount'],
            'cap_applied' => false,
            'breakdown' => $calc['breakdown'],
            'closed_by_user_id' => Auth::id(),
            'closed_at' => now(),
        ]);

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

        $session->update([
            'status' => ParkingSession::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'closed_by_user_id' => Auth::id(),
            'closed_at' => now(),
        ]);

        return $session->fresh();
    }

    /**
     * Cotiza (sin cobrar) el monto que se cobraria si el vehiculo saliera
     * en este momento. Util para pantalla previa al pago.
     */
    public function quote(ParkingSession $session, ?Carbon $exitAt = null): array
    {
        $exitAt = $exitAt ?: now();
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

    protected function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($plate)));
    }
}
