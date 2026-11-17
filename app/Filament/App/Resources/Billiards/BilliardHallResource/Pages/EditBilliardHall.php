<?php

namespace App\Filament\App\Resources\Billiards\BilliardHallResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardHallResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBilliardHall extends EditRecord
{
    protected static string $resource = BilliardHallResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
