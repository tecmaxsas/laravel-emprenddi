<?php

namespace App\Filament\App\Resources\Hotel\HotelResource\Pages;

use App\Filament\App\Resources\Hotel\HotelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHotel extends EditRecord
{
    protected static string $resource = HotelResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
