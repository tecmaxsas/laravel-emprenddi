<?php

namespace App\Filament\App\Resources\CommissionRuleResource\Pages;

use App\Filament\App\Resources\CommissionRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommissionRules extends ListRecords
{
    protected static string $resource = CommissionRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva regla'),
        ];
    }
}
