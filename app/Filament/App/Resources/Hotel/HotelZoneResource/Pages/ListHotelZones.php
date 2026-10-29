<?php

namespace App\Filament\App\Resources\Hotel\HotelZoneResource\Pages;

use App\Filament\App\Resources\Hotel\HotelZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHotelZones extends ListRecords
{
    protected static string $resource = HotelZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
