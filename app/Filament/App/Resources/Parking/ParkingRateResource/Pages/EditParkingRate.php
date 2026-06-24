<?php

namespace App\Filament\App\Resources\Parking\ParkingRateResource\Pages;

use App\Filament\App\Resources\Parking\ParkingRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditParkingRate extends EditRecord
{
    protected static string $resource = ParkingRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
