<?php

namespace App\Filament\App\Resources\PartnerResource\Pages;

use App\Filament\App\Resources\PartnerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPartners extends ListRecords
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo socio'),
        ];
    }
}
