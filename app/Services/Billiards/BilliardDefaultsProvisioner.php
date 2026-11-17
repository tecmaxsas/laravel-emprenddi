<?php

namespace App\Services\Billiards;

use App\Models\Billiards\BilliardTableType;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los tipos de mesa de un salon colombiano recien activado.
 *
 * No crea salas ni tarifas: esas las pone el dueño con sus propios precios.
 * Los tipos si, porque son los mismos en todo el pais y nadie quiere
 * teclearlos.
 */
class BilliardDefaultsProvisioner
{
    public const DEFAULT_TYPES = [
        ['code' => BilliardTableType::CODE_POOL, 'name' => 'Pool', 'icon' => '🎱', 'sort_order' => 10],
        ['code' => BilliardTableType::CODE_TRES_BANDAS, 'name' => 'Tres bandas', 'icon' => '🔴', 'sort_order' => 20],
        ['code' => BilliardTableType::CODE_MINIPOOL, 'name' => 'Mini pool', 'icon' => '🎯', 'sort_order' => 30],
        ['code' => BilliardTableType::CODE_RANA, 'name' => 'Rana / Sapo', 'icon' => '🐸', 'sort_order' => 40],
        ['code' => BilliardTableType::CODE_PINGPONG, 'name' => 'Ping pong', 'icon' => '🏓', 'sort_order' => 50],
    ];

    /** @return array{created: int, existing: int} */
    public function provision(Company $company): array
    {
        return DB::transaction(function () use ($company) {
            $creados = 0;
            $existentes = 0;

            foreach (self::DEFAULT_TYPES as $fila) {
                $existe = BilliardTableType::query()
                    ->where('company_id', $company->id)
                    ->where('code', $fila['code'])
                    ->exists();

                if ($existe) {
                    $existentes++;

                    continue;
                }

                BilliardTableType::create([
                    'company_id' => $company->id,
                    'code' => $fila['code'],
                    'name' => $fila['name'],
                    'icon' => $fila['icon'],
                    'sort_order' => $fila['sort_order'],
                    'active' => true,
                ]);

                $creados++;
            }

            return ['created' => $creados, 'existing' => $existentes];
        });
    }
}
