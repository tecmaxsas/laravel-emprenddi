<?php

namespace App\Filament\App\Resources\Parking\ParkingMembershipResource\Pages;

use App\Filament\App\Resources\Parking\ParkingMembershipResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditParkingMembership extends EditRecord
{
    protected static string $resource = ParkingMembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['plates'] = $this->normalizePlates($data['plates'] ?? []);
        return $data;
    }

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
