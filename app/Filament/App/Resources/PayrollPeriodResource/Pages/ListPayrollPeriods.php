<?php

namespace App\Filament\App\Resources\PayrollPeriodResource\Pages;

use App\Filament\App\Resources\PayrollPeriodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPayrollPeriods extends ListRecords
{
    protected static string $resource = PayrollPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo período'),
        ];
    }
}
