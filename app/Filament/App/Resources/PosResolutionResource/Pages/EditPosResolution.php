<?php

namespace App\Filament\App\Resources\PosResolutionResource\Pages;

use App\Filament\App\Resources\PosResolutionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPosResolution extends EditRecord
{
    protected static string $resource = PosResolutionResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
