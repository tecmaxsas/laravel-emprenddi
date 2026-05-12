<?php

namespace App\Filament\App\Resources\InventoryTransferResource\Pages;

use App\Filament\App\Resources\InventoryTransferResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryTransfers extends ListRecords
{
    protected static string $resource = InventoryTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva transferencia'),
        ];
    }
}
