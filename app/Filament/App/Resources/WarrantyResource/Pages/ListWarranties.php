<?php

namespace App\Filament\App\Resources\WarrantyResource\Pages;

use App\Filament\App\Resources\WarrantyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva garantía'),
        ];
    }
}
