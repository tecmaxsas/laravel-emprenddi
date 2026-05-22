<?php

namespace App\Filament\App\Resources\PayrollSettlementResource\Pages;

use App\Filament\App\Resources\PayrollSettlementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPayrollSettlements extends ListRecords
{
    protected static string $resource = PayrollSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva liquidación'),
        ];
    }
}
