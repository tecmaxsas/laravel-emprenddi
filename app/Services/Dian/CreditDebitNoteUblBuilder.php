<?php

namespace App\Services\Dian;

use App\Models\CreditDebitNote;
use App\Models\Tax;
use App\Models\ThirdParty;

/**
 * Construye el payload UBL 2.1 para NC (credit-note) y ND (debit-note) hacia
 * apidian. La estructura es prácticamente idéntica al de la factura
 * (SaleInvoiceUblBuilder) salvo por:
 *   - type_document_id: 4 (NC) o 5 (ND)
 *   - billing_reference: SIEMPRE obligatorio, apunta a la factura original
 *   - reason: code DIAN del motivo
 *   - credit_note_lines / debit_note_lines en vez de invoice_lines
 */
class CreditDebitNoteUblBuilder
{
    public function build(CreditDebitNote $note): array
    {
        $note->loadMissing(['lines.product', 'lines.tax', 'customer', 'location', 'saleInvoice']);

        $invoice = $note->saleInvoice;

        $document = [
            'type_document_id' => $note->dianTypeDocumentId(),  // 4=NC, 5=ND
            'prefix' => $note->prefix,
            'number' => $note->number,
            'date' => $note->date?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'time' => $note->posted_at?->format('H:i:s') ?? now()->format('H:i:s'),
            'sendmail' => true,

            // Referencia OBLIGATORIA a la factura original.
            'billing_reference' => [
                'number' => $invoice?->fullNumber() ?? '',
                'uuid' => $invoice?->cufe ?? '',
                'issue_date' => $invoice?->date?->format('Y-m-d') ?? '',
            ],

            // Razón DIAN del documento (código + descripción libre)
            'discrepancy_response' => [
                'correction_concept_id' => $note->reason_code,
                'correction_concept_description' => $note->reason_description ?: $note->reasonLabel(),
            ],

            'customer' => $this->buildCustomer($note->customer),
            'tax_totals' => $this->buildTaxTotals($note),
            'legal_monetary_totals' => $this->buildLegalMonetaryTotals($note),
        ];

        // Resolución (si tiene)
        if ($note->dianResolution?->resolution_number) {
            $document['resolution_number'] = $note->dianResolution->resolution_number;
        }

        // Líneas con la key correcta según tipo
        $linesKey = $note->isCredit() ? 'credit_note_lines' : 'debit_note_lines';
        $document[$linesKey] = $this->buildLines($note);

        return $document;
    }

    protected function buildCustomer(?ThirdParty $customer): array
    {
        // Reusamos la misma lógica del SaleInvoiceUblBuilder
        if (! $customer) {
            return [
                'identification_number' => '222222222',
                'name' => 'Consumidor Final',
                'email' => 'consumidorfinal@gmail.com',
                'merchant_registration' => '0000000-00',
                'type_document_identification_id' => SaleInvoiceUblBuilder::DOCUMENT_TYPE_MAP['cc'],
                'type_organization_id' => 2,
                'type_liability_id' => SaleInvoiceUblBuilder::DEFAULT_LIABILITY_ID,
                'type_regime_id' => 49,
                'municipality_id' => SaleInvoiceUblBuilder::DEFAULT_MUNICIPALITY_ID,
            ];
        }

        $isFinalConsumer = $customer->document_number === '222222222';

        $payload = [
            'identification_number' => $customer->document_number,
            'name' => $customer->name,
            'email' => $isFinalConsumer
                ? 'consumidorfinal@gmail.com'
                : (filter_var($customer->email, FILTER_SANITIZE_EMAIL) ?: 'sin@email.com'),
            'merchant_registration' => '0000000-00',
            'type_document_identification_id' => SaleInvoiceUblBuilder::DOCUMENT_TYPE_MAP[$customer->document_type] ?? SaleInvoiceUblBuilder::DOCUMENT_TYPE_MAP['cc'],
            'type_organization_id' => $customer->person_type === 'juridica' ? 1 : 2,
            'type_liability_id' => SaleInvoiceUblBuilder::DEFAULT_LIABILITY_ID,
            'type_regime_id' => SaleInvoiceUblBuilder::REGIME_MAP[$customer->regime_type] ?? 49,
            'municipality_id' => $customer->dian_municipality_id ?? SaleInvoiceUblBuilder::DEFAULT_MUNICIPALITY_ID,
        ];

        if (! empty($customer->dv) && $customer->dv !== 'NULL') {
            $payload['dv'] = (int) $customer->dv;
        }
        if (! empty($customer->phone)) $payload['phone'] = $customer->phone;
        if (! empty($customer->address)) $payload['address'] = $customer->address;

        return $payload;
    }

    protected function buildTaxTotals(CreditDebitNote $note): array
    {
        $grouped = [];

        foreach ($note->lines as $line) {
            if ((float) $line->tax_amount <= 0 || ! $line->tax) continue;

            $taxId = SaleInvoiceUblBuilder::TAX_ID_MAP[$line->tax->type] ?? 1;
            if (in_array($taxId, [5, 6, 7], true)) continue;  // retenciones aparte

            $key = $taxId.'_'.$line->tax_rate;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'tax_id' => $taxId,
                    'tax_amount' => 0.0,
                    'percent' => (float) $line->tax_rate,
                    'taxable_amount' => 0.0,
                ];
            }

            $grouped[$key]['tax_amount'] += (float) $line->tax_amount;
            $grouped[$key]['taxable_amount'] += (float) $line->subtotal - (float) $line->discount_amount;
        }

        return collect(array_values($grouped))->map(fn ($g) => [
            'tax_id' => $g['tax_id'],
            'tax_amount' => number_format($g['tax_amount'], 2, '.', ''),
            'percent' => $g['percent'],
            'taxable_amount' => number_format($g['taxable_amount'], 2, '.', ''),
        ])->all();
    }

    protected function buildLegalMonetaryTotals(CreditDebitNote $note): array
    {
        $lineExtension = (float) $note->subtotal - (float) $note->discount_total;
        $taxExclusive = $lineExtension;
        $taxInclusive = $lineExtension + (float) $note->tax_total;

        return [
            'line_extension_amount' => number_format($lineExtension, 2, '.', ''),
            'tax_exclusive_amount' => number_format($taxExclusive, 2, '.', ''),
            'tax_inclusive_amount' => number_format($taxInclusive, 2, '.', ''),
            'allowance_total_amount' => number_format((float) $note->discount_total, 2, '.', ''),
            'charge_total_amount' => '0.00',
            'payable_amount' => number_format((float) $note->total, 2, '.', ''),
        ];
    }

    protected function buildLines(CreditDebitNote $note): array
    {
        $lines = [];

        foreach ($note->lines as $line) {
            $unitMeasureId = SaleInvoiceUblBuilder::UNIT_MEASURE_MAP[$line->product?->unit_of_measure] ?? 70;

            $linePayload = [
                'unit_measure_id' => $unitMeasureId,
                'invoiced_quantity' => number_format((float) $line->quantity, 2, '.', ''),
                'line_extension_amount' => number_format((float) $line->subtotal - (float) $line->discount_amount, 2, '.', ''),
                'free_of_charge_indicator' => false,
                'description' => $line->description,
                'notes' => null,
                'code' => $line->product?->code ?? '',
                'type_item_identification_id' => 4,
                'price_amount' => number_format((float) $line->unit_price, 2, '.', ''),
                'base_quantity' => number_format((float) $line->quantity, 2, '.', ''),
            ];

            if ((float) $line->discount_amount > 0) {
                $linePayload['allowance_charges'] = [[
                    'discount_id' => 10,
                    'charge_indicator' => false,
                    'allowance_charge_reason' => 'Descuento línea',
                    'amount' => number_format((float) $line->discount_amount, 2, '.', ''),
                    'base_amount' => number_format((float) $line->subtotal, 2, '.', ''),
                ]];
            }

            if ((float) $line->tax_amount > 0 && $line->tax) {
                $taxId = SaleInvoiceUblBuilder::TAX_ID_MAP[$line->tax->type] ?? 1;
                if (! in_array($taxId, [5, 6, 7], true)) {
                    $linePayload['tax_totals'] = [[
                        'tax_id' => $taxId,
                        'tax_amount' => number_format((float) $line->tax_amount, 2, '.', ''),
                        'percent' => (float) $line->tax_rate,
                        'taxable_amount' => number_format((float) $line->subtotal - (float) $line->discount_amount, 2, '.', ''),
                    ]];
                }
            }

            $lines[] = $linePayload;
        }

        return $lines;
    }
}
