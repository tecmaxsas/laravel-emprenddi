<?php

namespace App\Filament\App\Resources\Billiards\BilliardRateResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBilliardRates extends ListRecords
{
    protected static string $resource = BilliardRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
