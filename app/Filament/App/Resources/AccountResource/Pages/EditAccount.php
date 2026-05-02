<?php

namespace App\Filament\App\Resources\AccountResource\Pages;

use App\Filament\App\Resources\AccountResource;
use App\Models\Account;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (Account $record) => ! $record->is_system),
        ];
    }
}
