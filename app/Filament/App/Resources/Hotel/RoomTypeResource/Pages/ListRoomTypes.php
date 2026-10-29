<?php

namespace App\Filament\App\Resources\Hotel\RoomTypeResource\Pages;

use App\Filament\App\Resources\Hotel\RoomTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoomTypes extends ListRecords
{
    protected static string $resource = RoomTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
