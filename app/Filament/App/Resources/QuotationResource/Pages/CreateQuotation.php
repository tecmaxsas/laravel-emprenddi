<?php

namespace App\Filament\App\Resources\QuotationResource\Pages;

use App\Filament\App\Resources\QuotationResource;
use App\Models\Company;
use App\Services\Sales\QuotationNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'COT';
        $data['number'] = app(QuotationNumberer::class)->next($company, $prefix);

        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        $lineNum = 1;

        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum, &$subtotal, &$discount, &$tax, &$total) {
            $line['line_number'] = $lineNum++;
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $discount += (float) ($line['discount_amount'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);
            return $line;
        })->all();

        $data['subtotal'] = $subtotal;
        $data['discount_total'] = $discount;
        $data['tax_total'] = $tax;
        $data['total'] = $total;

        return $data;
    }
}
