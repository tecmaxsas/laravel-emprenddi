<?php

namespace App\Filament\App\Resources\Hotel\RoomTypeResource\Pages;

use App\Filament\App\Resources\Hotel\RoomTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRoomType extends EditRecord
{
    protected static string $resource = RoomTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
