<?php

namespace App\Filament\App\Resources\Billiards\BilliardTableResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardTableResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBilliardTable extends EditRecord
{
    protected static string $resource = BilliardTableResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
