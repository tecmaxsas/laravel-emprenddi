<?php

namespace App\Filament\App\Resources\Restaurant\TableResource\Pages;

use App\Filament\App\Resources\Restaurant\TableResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTables extends ListRecords
{
    protected static string $resource = TableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('openMap')
                ->label('Mapa del salón')
                ->icon('heroicon-o-map')
                ->color('info')
                ->url(fn () => TableResource::getUrl('map')),
            Actions\CreateAction::make()->label('Nueva mesa'),
        ];
    }
}
