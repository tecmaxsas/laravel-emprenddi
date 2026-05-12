<?php

namespace App\Filament\App\Resources\Restaurant\TableResource\Pages;

use App\Filament\App\Resources\Restaurant\TableResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTable extends EditRecord
{
    protected static string $resource = TableResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
