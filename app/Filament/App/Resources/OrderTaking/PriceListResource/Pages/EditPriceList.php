<?php

namespace App\Filament\App\Resources\OrderTaking\PriceListResource\Pages;

use App\Filament\App\Resources\OrderTaking\PriceListResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPriceList extends EditRecord
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
