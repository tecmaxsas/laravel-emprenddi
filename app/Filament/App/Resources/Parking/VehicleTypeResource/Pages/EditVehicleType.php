<?php

namespace App\Filament\App\Resources\Parking\VehicleTypeResource\Pages;

use App\Filament\App\Resources\Parking\VehicleTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVehicleType extends EditRecord
{
    protected static string $resource = VehicleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
