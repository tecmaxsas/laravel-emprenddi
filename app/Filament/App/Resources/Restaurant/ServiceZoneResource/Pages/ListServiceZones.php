<?php

namespace App\Filament\App\Resources\Restaurant\ServiceZoneResource\Pages;

use App\Filament\App\Resources\Restaurant\ServiceZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListServiceZones extends ListRecords
{
    protected static string $resource = ServiceZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nueva zona')];
    }
}
