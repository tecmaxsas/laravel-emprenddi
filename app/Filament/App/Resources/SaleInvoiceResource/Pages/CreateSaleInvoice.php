<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Services\Sales\DocumentNumberer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSaleInvoice extends CreateRecord
{
    protected static string $resource = SaleInvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';
        $data['payment_status'] = 'pendiente';

        // Consecutivo de la resolución (POS o Electrónica) de la sede.
        $kind = $data['invoice_kind'] ?? 'electronic';
        $doc = app(DocumentNumberer::class)->reserveForLocation(
            (int) $data['location_id'],
            $kind,
        );
        $data['prefix'] = $doc['prefix'];
        $data['number'] = $doc['number'];
        $data['invoice_kind'] = $doc['kind'];
        $data['dian_resolution_id'] = $doc['resolution_id'];

        // Recompute totals desde las líneas (defensa por si las hidden no se llenaron)
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

        $retentionTotal = collect($data['retentions'] ?? [])
            ->sum(fn ($r) => (float) ($r['amount'] ?? 0));

        $data['subtotal'] = $subtotal;
        $data['discount_total'] = $discount;
        $data['tax_total'] = $tax;
        $data['total'] = $total;
        $data['retention_total'] = $retentionTotal;
        $data['net_payable'] = $total - $retentionTotal;

        return $data;
    }
}
