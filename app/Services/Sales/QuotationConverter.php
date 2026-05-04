<?php

namespace App\Services\Sales;

use App\Models\Company;
use App\Models\Quotation;
use App\Models\SaleInvoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte una cotización aprobada en SaleInvoice draft.
 * El usuario después postea la factura por el flujo normal (asiento +
 * inventario + DIAN).
 *
 * Idempotente parcial: si la cotización ya está convertida, devuelve la
 * factura previamente generada (no crea una segunda).
 */
class QuotationConverter
{
    public function convert(Quotation $quotation): SaleInvoice
    {
        if ($quotation->isConverted() && $quotation->convertedTo) {
            return $quotation->convertedTo;
        }

        if (! $quotation->canBeConverted()) {
            throw new RuntimeException('Esta cotización no puede convertirse — debe estar en estado "Enviada" o "Aprobada".');
        }

        $quotation->loadMissing('lines');

        if ($quotation->lines->isEmpty()) {
            throw new RuntimeException('La cotización no tiene líneas.');
        }

        return DB::transaction(function () use ($quotation) {
            $company = Company::find($quotation->company_id);

            $invoice = SaleInvoice::create([
                'company_id' => $quotation->company_id,
                'location_id' => $quotation->location_id,
                'third_party_id' => $quotation->third_party_id,
                'prefix' => 'FV',
                'number' => app(SaleInvoiceNumberer::class)->next($company, 'FV'),
                'date' => now()->toDateString(),
                'currency' => $quotation->currency,
                'exchange_rate' => $quotation->exchange_rate,
                'status' => SaleInvoice::STATUS_DRAFT,
                'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
                'created_by_user_id' => Auth::id(),
                'seller_user_id' => $quotation->seller_user_id ?: Auth::id(),
                'subtotal' => $quotation->subtotal,
                'discount_total' => $quotation->discount_total,
                'tax_total' => $quotation->tax_total,
                'total' => $quotation->total,
                'net_payable' => $quotation->total,
                'description' => 'Convertida desde cotización '.$quotation->fullNumber(),
                'notes' => $quotation->notes,
            ]);

            foreach ($quotation->lines as $line) {
                $invoice->lines()->create([
                    'line_number' => $line->line_number,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percentage' => $line->discount_percentage,
                    'discount_amount' => $line->discount_amount,
                    'tax_id' => $line->tax_id,
                    'tax_rate' => $line->tax_rate,
                    'tax_amount' => $line->tax_amount,
                    'subtotal' => $line->subtotal,
                    'total' => $line->total,
                ]);
            }

            $quotation->update([
                'status' => Quotation::STATUS_CONVERTED,
                'converted_to_sale_invoice_id' => $invoice->id,
                'converted_at' => now(),
            ]);

            return $invoice;
        });
    }
}
