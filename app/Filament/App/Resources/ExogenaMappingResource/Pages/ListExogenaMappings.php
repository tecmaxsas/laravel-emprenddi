<?php

namespace App\Filament\App\Resources\ExogenaMappingResource\Pages;

use App\Filament\App\Resources\ExogenaMappingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExogenaMappings extends ListRecords
{
    protected static string $resource = ExogenaMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Mapear cuenta'),
        ];
    }
}
