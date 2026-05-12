<?php

namespace App\Filament\App\Resources\FiscalPeriodResource\Pages;

use App\Filament\App\Resources\FiscalPeriodResource;
use App\Services\Accounting\FiscalPeriodGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFiscalPeriod extends EditRecord
{
    protected static string $resource = FiscalPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        FiscalPeriodGuard::flushCache();
    }
}
