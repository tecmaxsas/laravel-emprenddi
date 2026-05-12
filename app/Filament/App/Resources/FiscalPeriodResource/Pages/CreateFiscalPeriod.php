<?php

namespace App\Filament\App\Resources\FiscalPeriodResource\Pages;

use App\Filament\App\Resources\FiscalPeriodResource;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FiscalPeriodGuard;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFiscalPeriod extends CreateRecord
{
    protected static string $resource = FiscalPeriodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;

        // Crear el período en estado cerrado por default — el caso de uso
        // es "el contador cierra el mes pasado", no "predefine periodos abiertos".
        $data['status'] = $data['status'] ?? FiscalPeriod::STATUS_CLOSED;
        if ($data['status'] === FiscalPeriod::STATUS_CLOSED) {
            $data['locked_at'] = now();
            $data['locked_by_user_id'] = Auth::id();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        FiscalPeriodGuard::flushCache();
    }
}
