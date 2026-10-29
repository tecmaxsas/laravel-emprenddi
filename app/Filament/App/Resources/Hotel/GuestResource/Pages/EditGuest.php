<?php

namespace App\Filament\App\Resources\Hotel\GuestResource\Pages;

use App\Filament\App\Resources\Hotel\GuestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGuest extends EditRecord
{
    protected static string $resource = GuestResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
