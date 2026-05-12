<?php

namespace App\Filament\App\Resources\FiscalPeriodResource\Pages;

use App\Filament\App\Resources\FiscalPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFiscalPeriods extends ListRecords
{
    protected static string $resource = FiscalPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Definir período'),
        ];
    }
}
