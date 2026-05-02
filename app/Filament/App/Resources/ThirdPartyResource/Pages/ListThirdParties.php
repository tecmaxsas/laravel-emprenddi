<?php

namespace App\Filament\App\Resources\ThirdPartyResource\Pages;

use App\Filament\App\Resources\ThirdPartyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListThirdParties extends ListRecords
{
    protected static string $resource = ThirdPartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo tercero'),
        ];
    }
}
