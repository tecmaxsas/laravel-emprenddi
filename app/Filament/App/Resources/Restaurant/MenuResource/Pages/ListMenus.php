<?php

namespace App\Filament\App\Resources\Restaurant\MenuResource\Pages;

use App\Filament\App\Resources\Restaurant\MenuResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMenus extends ListRecords
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nueva carta')];
    }
}
