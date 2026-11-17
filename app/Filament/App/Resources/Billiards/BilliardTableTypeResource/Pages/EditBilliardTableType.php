<?php

namespace App\Filament\App\Resources\Billiards\BilliardTableTypeResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardTableTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBilliardTableType extends EditRecord
{
    protected static string $resource = BilliardTableTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
