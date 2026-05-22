<?php

namespace App\Filament\App\Resources\PayrollParameterResource\Pages;

use App\Filament\App\Resources\PayrollParameterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPayrollParameters extends ListRecords
{
    protected static string $resource = PayrollParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nuevo año'),
        ];
    }
}
