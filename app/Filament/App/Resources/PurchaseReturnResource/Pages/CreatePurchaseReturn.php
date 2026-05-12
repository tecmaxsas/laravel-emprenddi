<?php

namespace App\Filament\App\Resources\PurchaseReturnResource\Pages;

use App\Filament\App\Resources\PurchaseReturnResource;
use App\Models\Company;
use App\Services\Purchases\PurchaseReturnNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseReturn extends CreateRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'DEV';
        $data['number'] = app(PurchaseReturnNumberer::class)->next($company, $prefix);

        // Recompute totals
        $subtotal = 0; $tax = 0; $total = 0; $lineNum = 1;
        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum, &$subtotal, &$tax, &$total) {
            $line['line_number'] = $lineNum++;
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);
            return $line;
        })->all();

        $data['subtotal'] = $subtotal;
        $data['tax_total'] = $tax;
        $data['total'] = $total;

        return $data;
    }
}
