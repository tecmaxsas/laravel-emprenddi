<?php

namespace App\Filament\App\Resources\Restaurant\PrinterResource\Pages;

use App\Filament\App\Resources\Restaurant\PrinterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPrinter extends EditRecord
{
    protected static string $resource = PrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
