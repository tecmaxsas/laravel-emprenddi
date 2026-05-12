<?php

namespace App\Filament\App\Resources\Restaurant\DriverResource\Pages;

use App\Filament\App\Resources\Restaurant\DriverResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nuevo repartidor')];
    }
}
