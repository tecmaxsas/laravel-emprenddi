<?php

namespace App\Filament\App\Resources\Restaurant\DriverResource\Pages;

use App\Filament\App\Resources\Restaurant\DriverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
