<?php

namespace App\Filament\App\Resources\Parking\ParkingSpaceResource\Pages;

use App\Filament\App\Resources\Parking\ParkingSpaceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditParkingSpace extends EditRecord
{
    protected static string $resource = ParkingSpaceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
