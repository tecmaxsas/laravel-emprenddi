<?php

namespace App\Filament\App\Resources\CostCenterResource\Pages;

use App\Filament\App\Resources\CostCenterResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCostCenter extends CreateRecord
{
    protected static string $resource = CostCenterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        return $data;
    }
}
