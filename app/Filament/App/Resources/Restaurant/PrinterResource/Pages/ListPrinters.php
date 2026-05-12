<?php

namespace App\Filament\App\Resources\Restaurant\PrinterResource\Pages;

use App\Filament\App\Resources\Restaurant\PrinterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPrinters extends ListRecords
{
    protected static string $resource = PrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nueva impresora')];
    }
}
