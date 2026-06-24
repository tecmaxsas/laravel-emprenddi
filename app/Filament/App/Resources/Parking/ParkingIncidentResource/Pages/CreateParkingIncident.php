<?php

namespace App\Filament\App\Resources\Parking\ParkingIncidentResource\Pages;

use App\Filament\App\Resources\Parking\ParkingIncidentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateParkingIncident extends CreateRecord
{
    protected static string $resource = ParkingIncidentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reported_by_user_id'] = auth()->id();
        if (! empty($data['plate'])) {
            $data['plate'] = strtoupper(preg_replace('/\s+/', '', trim((string) $data['plate'])));
        }
        return $data;
    }
}
