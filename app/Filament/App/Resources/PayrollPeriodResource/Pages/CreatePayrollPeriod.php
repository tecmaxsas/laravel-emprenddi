<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\Pages;

use App\Filament\App\Resources\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use Filament\Resources\Pages\CreateRecord;

class CreatePayrollPeriod extends CreateRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = PayrollPeriod::STATUS_DRAFT;

        return $data;
    }
}
