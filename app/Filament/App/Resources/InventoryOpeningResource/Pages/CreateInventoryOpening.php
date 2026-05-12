<?php

namespace App\Filament\App\Resources\InventoryOpeningResource\Pages;

use App\Filament\App\Resources\InventoryOpeningResource;
use App\Models\Company;
use App\Services\Inventory\InventoryOpeningNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateInventoryOpening extends CreateRecord
{
    protected static string $resource = InventoryOpeningResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'SI';
        $data['number'] = app(InventoryOpeningNumberer::class)->next($company, $prefix);

        $lineNum = 1;
        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum) {
            $line['line_number'] = $lineNum++;
            return $line;
        })->all();

        return $data;
    }
}
