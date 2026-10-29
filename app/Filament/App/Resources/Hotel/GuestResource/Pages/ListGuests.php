<?php

namespace App\Filament\App\Resources\Hotel\GuestResource\Pages;

use App\Filament\App\Resources\Hotel\GuestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGuests extends ListRecords
{
    protected static string $resource = GuestResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
