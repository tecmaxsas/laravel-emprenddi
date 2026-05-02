<?php

namespace App\Filament\App\Resources\TaxResource\Pages;

use App\Filament\App\Resources\TaxResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxes extends ListRecords
{
    protected static string $resource = TaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo impuesto'),
        ];
    }
}
