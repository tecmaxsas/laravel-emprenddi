<?php

namespace App\Filament\App\Resources\TaxResource\Pages;

use App\Filament\App\Resources\TaxResource;
use App\Models\Tax;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTax extends EditRecord
{
    protected static string $resource = TaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (Tax $record) => ! $record->is_system),
        ];
    }
}
