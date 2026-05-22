<?php

namespace App\Filament\App\Resources\SupportDocumentResource\Pages;

use App\Filament\App\Resources\SupportDocumentResource;
use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\SupportDocumentNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSupportDocument extends CreateRecord
{
    protected static string $resource = SupportDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['kind'] = PurchaseInvoice::KIND_SUPPORT_DOCUMENT;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = PurchaseInvoice::STATUS_DRAFT;
        $data['payment_status'] = PurchaseInvoice::PAYMENT_PENDIENTE;

        // Consecutivo interno del documento soporte por (compañía, prefijo).
        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'DS';
        $data['number'] = app(SupportDocumentNumberer::class)->next($company, $prefix);

        // Recalcula los totales desde las líneas (defensa por si las hidden
        // no se llenaron) y numera cada línea.
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
