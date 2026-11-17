<?php

namespace App\Filament\App\Resources\Billiards\BilliardTableTypeResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardTableTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBilliardTableTypes extends ListRecords
{
    protected static string $resource = BilliardTableTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
