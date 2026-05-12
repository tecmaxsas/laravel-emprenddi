<?php

namespace App\Filament\App\Resources\InventoryAdjustmentResource\Pages;

use App\Filament\App\Resources\InventoryAdjustmentResource;
use App\Models\Company;
use App\Services\Inventory\InventoryAdjustmentNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateInventoryAdjustment extends CreateRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'AJ';
        $data['number'] = app(InventoryAdjustmentNumberer::class)->next($company, $prefix);

        $lineNum = 1;
        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum) {
            $line['line_number'] = $lineNum++;
            return $line;
        })->all();

        return $data;
    }
}
