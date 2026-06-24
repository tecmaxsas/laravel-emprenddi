<?php

namespace App\Filament\App\Resources\Parking\VehicleTypeResource\Pages;

use App\Filament\App\Resources\Parking\VehicleTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVehicleTypes extends ListRecords
{
    protected static string $resource = VehicleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
