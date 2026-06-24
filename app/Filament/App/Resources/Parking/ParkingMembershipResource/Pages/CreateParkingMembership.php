<?php

namespace App\Filament\App\Resources\Parking\ParkingMembershipResource\Pages;

use App\Filament\App\Resources\Parking\ParkingMembershipResource;
use Filament\Resources\Pages\CreateRecord;

class CreateParkingMembership extends CreateRecord
{
    protected static string $resource = ParkingMembershipResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();
        $data['plates'] = $this->normalizePlates($data['plates'] ?? []);
        return $data;
    }

    /**
     * Convierte cada entrada del Repeater (que puede venir como string o como
     * array ['plate' => '...']) a array plano de placas en mayusculas y
     * sin espacios.
     */
    protected function normalizePlates(array $plates): array
    {
        $out = [];
        foreach ($plates as $p) {
            $value = is_array($p) ? ($p['plate'] ?? reset($p)) : $p;
            $value = strtoupper(preg_replace('/\s+/', '', trim((string) $value)));
            if ($value !== '') $out[] = $value;
        }
        return array_values(array_unique($out));
    }
}
