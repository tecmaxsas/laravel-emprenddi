<?php

namespace App\Filament\App\Resources\Billiards\BilliardRateResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBilliardRate extends CreateRecord
{
    protected static string $resource = BilliardRateResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
