<?php

namespace App\Filament\App\Resources\CurrencyResource\Pages;

use App\Filament\App\Resources\CurrencyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCurrencies extends ListRecords
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva moneda'),
        ];
    }
}
