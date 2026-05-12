<?php

namespace App\Filament\App\Resources\CurrencyResource\Pages;

use App\Filament\App\Resources\CurrencyResource;
use App\Services\Accounting\ExchangeRateService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        ExchangeRateService::flushCache();
    }
}
