<?php

namespace App\Filament\App\Resources\InventoryAdjustmentResource\Pages;

use App\Filament\App\Resources\InventoryAdjustmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryAdjustments extends ListRecords
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo ajuste'),
        ];
    }
}
