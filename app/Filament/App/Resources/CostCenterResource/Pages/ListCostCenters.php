<?php

namespace App\Filament\App\Resources\CostCenterResource\Pages;

use App\Filament\App\Resources\CostCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCostCenters extends ListRecords
{
    protected static string $resource = CostCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo centro de costo'),
        ];
    }
}
