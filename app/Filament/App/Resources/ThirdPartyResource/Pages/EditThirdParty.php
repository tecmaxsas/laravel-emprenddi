<?php

namespace App\Filament\App\Resources\ThirdPartyResource\Pages;

use App\Filament\App\Resources\ThirdPartyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditThirdParty extends EditRecord
{
    protected static string $resource = ThirdPartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
