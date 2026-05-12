<?php

namespace App\Filament\App\Resources\CurrencyResource\Pages;

use App\Filament\App\Resources\CurrencyResource;
use App\Services\Accounting\ExchangeRateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCurrency extends CreateRecord
{
    protected static string $resource = CurrencyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        return $data;
    }

    protected function afterCreate(): void
    {
        ExchangeRateService::flushCache();
    }
}
