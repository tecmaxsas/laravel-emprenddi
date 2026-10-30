<?php

namespace App\Filament\App\Resources\Hotel\RateSeasonResource\Pages;

use App\Filament\App\Resources\Hotel\RateSeasonResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRateSeasons extends ListRecords
{
    protected static string $resource = RateSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
