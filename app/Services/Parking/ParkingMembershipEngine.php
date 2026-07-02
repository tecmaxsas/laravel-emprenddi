<?php

namespace App\Services\Parking;

use App\Models\Parking\ParkingMembership;
use Carbon\CarbonInterface;

/**
 * Engine de mensualidades. Funciones puras (sin mutar DB) excepto el job de
 * vencimientos. Es consumido por ParkingSessionEngine al hacer checkIn y
 * checkOut para decidir si la sesion va con cobro o cubierta por convenio.
 */
class ParkingMembershipEngine
{
    /**
     * Busca una mensualidad/convenio activo que cubra (plate, lot, fecha).
     * Si encuentra varios (poco comun pero posible), prioriza el de mayor
     * end_date (el mas nuevo gana).
     */
    public function findCoverageFor(string $plate, int $parkingLotId, ?CarbonInterface $at = null): ?ParkingMembership
    {
        $at = $at ?: now();
        $plate = $this->normalizePlate($plate);
        if ($plate === '') return null;

        $candidates = ParkingMembership::query()
            ->where('parking_lot_id', $parkingLotId)
            ->where('status', ParkingMembership::STATUS_ACTIVE)
            ->whereDate('start_date', '<=', $at)
            ->whereDate('end_date', '>=', $at)
            // Filtro grueso en DB; el fino (placa) se hace en PHP porque el
            // JSON puede tener distintos formatos (mayusculas/espacios)
            ->whereJsonContains('plates', $plate)
            ->orderByDesc('end_date')
            ->get();

        foreach ($candidates as $m) {
            if ($m->coversPlate($plate)) {
                return $m;
            }
        }

        // Fallback: si el whereJsonContains no matchea por formato, busca
        // por end_date y filtra en PHP (mas lento pero robusto).
        if ($candidates->isEmpty()) {
            $broader = ParkingMembership::query()
                ->where('parking_lot_id', $parkingLotId)
                ->where('status', ParkingMembership::STATUS_ACTIVE)
                ->whereDate('start_date', '<=', $at)
                ->whereDate('end_date', '>=', $at)
                ->orderByDesc('end_date')
                ->get();
            foreach ($broader as $m) {
                if ($m->coversPlate($plate)) return $m;
            }
        }

        return null;
    }

    /**
     * Job de mantenimiento (lo invocara un command/scheduler en commit
     * posterior): marca como expiradas las mensualidades cuyo end_date
     * paso. Se ejecuta de noche a nivel de sistema (todas las empresas).
     *
     * IMPORTANTE: withoutGlobalScopes() es intencional para procesar
     * cross-tenant desde el scheduler. NO llamar desde UI.
     */
    public function markExpired(): int
    {
        return ParkingMembership::withoutGlobalScopes()
            ->where('status', ParkingMembership::STATUS_ACTIVE)
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => ParkingMembership::STATUS_EXPIRED]);
    }

    protected function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($plate)));
    }
}
