<?php

namespace App\Filament\App\Resources\InventoryTransferResource\Pages;

use App\Filament\App\Resources\InventoryTransferResource;
use App\Models\Company;
use App\Services\Inventory\InventoryTransferNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateInventoryTransfer extends CreateRecord
{
    protected static string $resource = InventoryTransferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'TR';
        $data['number'] = app(InventoryTransferNumberer::class)->next($company, $prefix);

        $lineNum = 1;
        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum) {
            $line['line_number'] = $lineNum++;
            return $line;
        })->all();

        return $data;
    }
}
