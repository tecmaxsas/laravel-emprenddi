<?php

namespace App\Filament\App\Resources\Parking\ParkingRateResource\Pages;

use App\Filament\App\Resources\Parking\ParkingRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListParkingRates extends ListRecords
{
    protected static string $resource = ParkingRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
