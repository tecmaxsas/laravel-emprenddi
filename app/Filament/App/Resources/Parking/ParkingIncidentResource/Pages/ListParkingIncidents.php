<?php

namespace App\Filament\App\Resources\Parking\ParkingIncidentResource\Pages;

use App\Filament\App\Resources\Parking\ParkingIncidentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListParkingIncidents extends ListRecords
{
    protected static string $resource = ParkingIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
