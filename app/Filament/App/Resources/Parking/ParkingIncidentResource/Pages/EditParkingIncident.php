<?php

namespace App\Filament\App\Resources\Parking\ParkingIncidentResource\Pages;

use App\Filament\App\Resources\Parking\ParkingIncidentResource;
use App\Models\Parking\ParkingIncident;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditParkingIncident extends EditRecord
{
    protected static string $resource = ParkingIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['plate'])) {
            $data['plate'] = strtoupper(preg_replace('/\s+/', '', trim((string) $data['plate'])));
        }
        // Si pasa a resuelto/descartado y no hay resolutor o fecha, los completa
        if (in_array($data['status'] ?? null, [
            ParkingIncident::STATUS_RESOLVED,
            ParkingIncident::STATUS_DISMISSED,
        ], true)) {
            $data['resolved_by_user_id'] = $data['resolved_by_user_id'] ?? auth()->id();
            $data['resolved_at'] = $data['resolved_at'] ?? now();
        }
        return $data;
    }
}
