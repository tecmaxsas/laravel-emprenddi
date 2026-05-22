<?php

namespace App\Filament\App\Resources\SupportDocumentResource\Pages;

use App\Filament\App\Resources\SupportDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportDocuments extends ListRecords
{
    protected static string $resource = SupportDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo documento soporte'),
        ];
    }
}
