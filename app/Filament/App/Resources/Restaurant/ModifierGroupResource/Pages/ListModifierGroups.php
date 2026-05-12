<?php

namespace App\Filament\App\Resources\Restaurant\ModifierGroupResource\Pages;

use App\Filament\App\Resources\Restaurant\ModifierGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListModifierGroups extends ListRecords
{
    protected static string $resource = ModifierGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nuevo grupo')];
    }
}
