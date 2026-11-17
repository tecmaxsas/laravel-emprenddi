<?php

namespace App\Filament\App\Resources\Billiards\BilliardRateResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBilliardRate extends EditRecord
{
    protected static string $resource = BilliardRateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
