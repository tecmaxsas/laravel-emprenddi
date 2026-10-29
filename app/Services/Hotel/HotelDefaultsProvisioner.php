<?php

namespace App\Services\Hotel;

use App\Models\Company;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\RoomType;
use Illuminate\Support\Facades\DB;

/**
 * Siembra lo mínimo para que una empresa que acaba de activar el módulo pueda
 * trabajar: los tipos de habitación canónicos.
 *
 * No crea habitaciones ni tarifas con cifras. Las tarifas son del negocio y
 * sembrarlas con un número inventado es peor que dejarlas en cero: una tarifa
 * en cero no deja alojar a nadie y obliga a configurarla, mientras que una
 * tarifa de ejemplo se queda puesta y algún día se le cobra a un huésped.
 */
class HotelDefaultsProvisioner
{
    public const TIPOS_POR_DEFECTO = [
        ['code' => 'SEN', 'name' => 'Sencilla', 'max_adults' => 1, 'max_children' => 0, 'beds' => '1 sencilla', 'sort_order' => 10],
        ['code' => 'DOB', 'name' => 'Doble', 'max_adults' => 2, 'max_children' => 1, 'beds' => '1 doble', 'sort_order' => 20],
        ['code' => 'TWIN', 'name' => 'Doble con dos camas', 'max_adults' => 2, 'max_children' => 1, 'beds' => '2 sencillas', 'sort_order' => 30],
        ['code' => 'TRI', 'name' => 'Triple', 'max_adults' => 3, 'max_children' => 1, 'beds' => '1 doble + 1 sencilla', 'sort_order' => 40],
        ['code' => 'FAM', 'name' => 'Familiar', 'max_adults' => 4, 'max_children' => 2, 'beds' => '2 dobles', 'sort_order' => 50],
        ['code' => 'SUITE', 'name' => 'Suite', 'max_adults' => 2, 'max_children' => 2, 'beds' => '1 king', 'sort_order' => 60],
    ];

    /**
     * @return array{creados: int, existentes: int}
     */
    public function provision(Company $company, ?Hotel $hotel = null): array
    {
        $hotel ??= Hotel::query()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first();

        if (! $hotel) {
            return ['creados' => 0, 'existentes' => 0];
        }

        return DB::transaction(function () use ($company, $hotel) {
            $creados = 0;
            $existentes = 0;

            foreach (self::TIPOS_POR_DEFECTO as $fila) {
                $encontrado = RoomType::query()
                    ->where('hotel_id', $hotel->id)
                    ->where('code', $fila['code'])
                    ->exists();

                if ($encontrado) {
                    $existentes++;

                    continue;
                }

                RoomType::create(array_merge($fila, [
                    'company_id' => $company->id,
                    'hotel_id' => $hotel->id,
                    'base_rate' => 0,
                    'extra_person_rate' => 0,
                    'active' => true,
                ]));

                $creados++;
            }

            return ['creados' => $creados, 'existentes' => $existentes];
        });
    }
}
