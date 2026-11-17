<?php

namespace App\Filament\App\Resources\Billiards\BilliardTableResource\Pages;

use App\Filament\App\Resources\Billiards\BilliardTableResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBilliardTable extends CreateRecord
{
    protected static string $resource = BilliardTableResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
