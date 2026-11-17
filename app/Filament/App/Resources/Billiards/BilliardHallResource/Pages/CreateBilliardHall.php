<?php

namespace App\Filament\App\Resources\Billiards\BilliardHallResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardHallResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBilliardHall extends CreateRecord
{
    protected static string $resource = BilliardHallResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
