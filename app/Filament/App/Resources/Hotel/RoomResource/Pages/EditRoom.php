<?php

namespace App\Filament\App\Resources\Hotel\RoomResource\Pages;

use App\Filament\App\Resources\Hotel\RoomResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRoom extends EditRecord
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
