<?php

namespace App\Filament\App\Resources\Hotel\RateSeasonResource\Pages;

use App\Filament\App\Resources\Hotel\RateSeasonResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRateSeason extends EditRecord
{
    protected static string $resource = RateSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
