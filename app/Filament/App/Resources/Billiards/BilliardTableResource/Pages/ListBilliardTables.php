<?php

namespace App\Filament\App\Resources\Billiards\BilliardTableResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardTableResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBilliardTables extends ListRecords
{
    protected static string $resource = BilliardTableResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
