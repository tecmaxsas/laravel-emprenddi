<?php

namespace App\Filament\App\Resources\Restaurant\ServiceZoneResource\Pages;

use App\Filament\App\Resources\Restaurant\ServiceZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditServiceZone extends EditRecord
{
    protected static string $resource = ServiceZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
