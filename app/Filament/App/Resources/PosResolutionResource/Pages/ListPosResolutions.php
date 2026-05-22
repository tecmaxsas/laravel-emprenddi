<?php

namespace App\Filament\App\Resources\PosResolutionResource\Pages;

use App\Filament\App\Resources\PosResolutionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPosResolutions extends ListRecords
{
    protected static string $resource = PosResolutionResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nueva resolución POS')];
    }
}
