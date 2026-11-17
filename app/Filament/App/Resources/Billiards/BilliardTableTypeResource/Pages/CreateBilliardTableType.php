<?php

namespace App\Filament\App\Resources\Billiards\BilliardTableTypeResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardTableTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBilliardTableType extends CreateRecord
{
    protected static string $resource = BilliardTableTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
