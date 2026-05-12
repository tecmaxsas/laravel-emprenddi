<?php

namespace App\Filament\SuperAdmin\Resources\AccountantResource\Pages;

use App\Filament\SuperAdmin\Resources\AccountantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccountant extends EditRecord
{
    protected static string $resource = AccountantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
