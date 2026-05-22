<?php

namespace App\Filament\App\Resources\ExogenaManualEntryResource\Pages;

use App\Filament\App\Resources\ExogenaManualEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExogenaManualEntries extends ListRecords
{
    protected static string $resource = ExogenaManualEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo registro'),
        ];
    }
}
