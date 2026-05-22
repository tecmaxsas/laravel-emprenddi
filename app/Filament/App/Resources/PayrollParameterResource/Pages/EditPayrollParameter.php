<?php

namespace App\Filament\App\Resources\PayrollParameterResource\Pages;

use App\Filament\App\Resources\PayrollParameterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPayrollParameter extends EditRecord
{
    protected static string $resource = PayrollParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
