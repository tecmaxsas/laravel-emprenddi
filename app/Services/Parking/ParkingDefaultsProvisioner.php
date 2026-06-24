<?php

namespace App\Services\Parking;

use App\Models\Company;
use App\Models\Parking\VehicleType;
use Illuminate\Support\Facades\DB;

/**
 * Sembradores idempotentes para una empresa que recien activa el modulo
 * parqueadero: crea los 4 tipos de vehiculo canonicos (CAR/MOTO/BIKE/TRUCK)
 * si no existen. No crea parqueaderos ni tarifas: eso va a mano por el
 * admin con sus propias reglas de negocio.
 */
class ParkingDefaultsProvisioner
{
    public const DEFAULT_TYPES = [
        ['code' => VehicleType::CODE_CAR, 'name' => 'Carro', 'icon' => '🚗', 'sort_order' => 10],
        ['code' => VehicleType::CODE_MOTORCYCLE, 'name' => 'Moto', 'icon' => '🏍️', 'sort_order' => 20],
        ['code' => VehicleType::CODE_BICYCLE, 'name' => 'Bicicleta', 'icon' => '🚲', 'sort_order' => 30],
        ['code' => VehicleType::CODE_TRUCK, 'name' => 'Camión', 'icon' => '🚚', 'sort_order' => 40],
    ];

    public function provision(Company $company): array
    {
        return DB::transaction(function () use ($company) {
            $created = 0;
            $existing = 0;
            foreach (self::DEFAULT_TYPES as $row) {
                $found = VehicleType::query()
                    ->where('company_id', $company->id)
                    ->where('code', $row['code'])
                    ->first();
                if ($found) {
                    $existing++;
                    continue;
                }
                VehicleType::create([
                    'company_id' => $company->id,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'icon' => $row['icon'],
                    'sort_order' => $row['sort_order'],
                    'active' => true,
                ]);
                $created++;
            }
            return ['created' => $created, 'existing' => $existing];
        });
    }
}
