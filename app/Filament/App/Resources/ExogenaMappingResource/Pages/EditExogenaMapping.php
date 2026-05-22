<?php

namespace App\Filament\App\Resources\ExogenaMappingResource\Pages;

use App\Filament\App\Resources\ExogenaMappingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExogenaMapping extends EditRecord
{
    protected static string $resource = ExogenaMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
