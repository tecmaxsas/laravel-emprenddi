<?php

namespace App\Filament\App\Resources\Restaurant\ModifierGroupResource\Pages;

use App\Filament\App\Resources\Restaurant\ModifierGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditModifierGroup extends EditRecord
{
    protected static string $resource = ModifierGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
