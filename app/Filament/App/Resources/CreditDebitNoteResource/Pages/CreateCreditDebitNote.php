<?php

namespace App\Filament\App\Resources\CreditDebitNoteResource\Pages;

use App\Filament\App\Resources\CreditDebitNoteResource;
use App\Models\Company;
use App\Models\SaleInvoice;
use App\Services\Sales\CreditDebitNoteNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCreditDebitNote extends CreateRecord
{
    protected static string $resource = CreditDebitNoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $type = $data['type'] ?? 'credit';
        $prefix = $data['prefix'] ?? ($type === 'credit' ? 'NC' : 'ND');
        $data['number'] = app(CreditDebitNoteNumberer::class)->next($company, $type, $prefix);

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
