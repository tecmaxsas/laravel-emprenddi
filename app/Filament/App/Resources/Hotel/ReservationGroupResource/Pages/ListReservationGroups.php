<?php

namespace App\Filament\App\Resources\Hotel\ReservationGroupResource\Pages;

use App\Filament\App\Resources\Hotel\ReservationGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReservationGroups extends ListRecords
{
    protected static string $resource = ReservationGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nuevo grupo')];
    }
}
