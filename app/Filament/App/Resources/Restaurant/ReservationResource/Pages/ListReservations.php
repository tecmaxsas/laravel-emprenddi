<?php

namespace App\Filament\App\Resources\Restaurant\ReservationResource\Pages;

use App\Filament\App\Resources\Restaurant\ReservationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nueva reserva')];
    }
}
