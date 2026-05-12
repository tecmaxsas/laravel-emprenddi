<?php

namespace App\Filament\App\Resources\PurchaseReturnResource\Pages;

use App\Filament\App\Resources\PurchaseReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseReturns extends ListRecords
{
    protected static string $resource = PurchaseReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva devolución'),
        ];
    }
}
