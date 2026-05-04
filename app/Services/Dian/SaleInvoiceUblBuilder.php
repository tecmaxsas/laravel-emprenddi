<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\Dian\Resolution;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;

/**
 * Construye el payload UBL 2.1 para apidian.emprenddi.com a partir de un
 * SaleInvoice contabilizado.
 *
 * Estructura basada en la referencia guardada en memory/reference_dian_invoice_payload.md
 * (FELAPI_InvoiceProcess.php del sistema previo del usuario).
 */
class SaleInvoiceUblBuilder
{
    /**
     * Mapping de Tax::type → DIAN tax_id
     */
    public const TAX_ID_MAP = [
        'vat' => 1,                   // IVA
        'consumption_tax' => 4,       // INC
        'vat_withholding' => 5,       // ReteIVA
        'income_withholding' => 6,    // ReteFuente / Renta
        'ica_withholding' => 7,       // ReteICA
    ];

    /**
     * Mapping de Product::unit_of_measure → DIAN unit_measure_id
     */
    public const UNIT_MEASURE_MAP = [
        'unit' => 70,
        'kg' => 44,
        'g' => 677,
        'l' => 1308,
        'ml' => 1184,
        'm' => 865,
        'cm' => 495,
        'm2' => 942,
        'm3' => 943,
    ];

    /**
     * Mapping de ThirdParty::document_type → DIAN type_document_identification_id
     */
    public const DOCUMENT_TYPE_MAP = [
        'cc' => 13,        // Cédula de ciudadanía
        'ce' => 22,        // Cédula de extranjería
        'ti' => 12,        // Tarjeta de identidad
        'nit' => 31,       // NIT
        'pasaporte' => 41, // Pasaporte
        'rut' => 31,       // RUT (mismo que NIT)
        'nuip' => 91,      // NUIP
        'die' => 42,       // Documento de identificación extranjero
    ];

    /**
     * Mapping de ThirdParty::regime_type → DIAN type_regime_id
     */
    public const REGIME_MAP = [
        'comun' => 48,                 // Responsable de IVA
        'gran_contribuyente' => 48,    // Responsable de IVA (gran contribuyente)
        'no_responsable_iva' => 49,    // No responsable de IVA
        'simplificado' => 49,          // Régimen simplificado = No responsable
    ];

    /**
     * Default municipio fallback (149 = Bogotá D.C. en el catálogo DIAN, igual
     * que usa el código de referencia previo).
     */
    public const DEFAULT_MUNICIPALITY_ID = 149;

    /**
     * Default type_liability si no está configurado (R-99-PN consumidor final).
     */
    public const DEFAULT_LIABILITY_ID = 117;

    public function build(SaleInvoice $invoice): array
    {
        $invoice->loadMissing(['lines.product', 'lines.tax', 'retentions.tax', 'customer', 'location', 'payments', 'company']);

        $document = [
            'type_document_id' => 1,
            'prefix' => $invoice->prefix,
            'number' => $invoice->number,
            'date' => $invoice->date?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'time' => $invoice->posted_at?->format('H:i:s') ?? now()->format('H:i:s'),
            'resolution_number' => $this->resolveResolutionNumber($invoice),
            'notes' => $invoice->notes ?: null,
            'sendmail' => true,
        ];

        $document['customer'] = $this->buildCustomer($invoice->customer);
        $document['payment_form'] = $this->buildPaymentForms($invoice);

        // Descuento general (si hay)
        $allowanceCharges = $this->buildDocumentAllowanceCharges($invoice);
        if (! empty($allowanceCharges)) {
            $document['allowance_charges'] = $allowanceCharges;
        }

        // Tax totals agrupados (IVA, INC)
        $document['tax_totals'] = $this->buildTaxTotals($invoice);

        // Retenciones (clave del Envío 2 — campo with_holding_tax_total)
        $withHolding = $this->buildWithHoldingTaxTotal($invoice);
        if (! empty($withHolding)) {
            $document['with_holding_tax_total'] = $withHolding;
        }

        $document['legal_monetary_totals'] = $this->buildLegalMonetaryTotals($invoice);
        $document['invoice_lines'] = $this->buildInvoiceLines($invoice);

        return $document;
    }

    protected function resolveResolutionNumber(SaleInvoice $invoice): string
    {
        // Snapshot persistido al postear (preferido)
        if ($invoice->dian_resolution_id) {
            $resolution = Resolution::find($invoice->dian_resolution_id);
            if ($resolution?->resolution_number) {
                return $resolution->resolution_number;
            }
        }

        // Fallback: la activa de la sede
        $assignment = $invoice->location?->activeResolution(documentTypeId: 1);
        return $assignment?->resolution?->resolution_number ?? '';
    }

    protected function buildCustomer(?ThirdParty $customer): array
    {
        if (! $customer) {
            // Fallback: consumidor final genérico (no debería pasar — siempre hay default)
            return [
                'identification_number' => '222222222',
                'name' => 'Consumidor Final',
                'email' => 'consumidorfinal@gmail.com',
                'merchant_registration' => '0000000-00',
                'type_document_identification_id' => self::DOCUMENT_TYPE_MAP['cc'],
                'type_organization_id' => 2,
                'type_liability_id' => self::DEFAULT_LIABILITY_ID,
                'type_regime_id' => 49,
                'municipality_id' => self::DEFAULT_MUNICIPALITY_ID,
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
            'type_document_identification_id' => self::DOCUMENT_TYPE_MAP[$customer->document_type] ?? self::DOCUMENT_TYPE_MAP['cc'],
            'type_organization_id' => $customer->person_type === 'juridica' ? 1 : 2,
            'type_liability_id' => self::DEFAULT_LIABILITY_ID,
            'type_regime_id' => self::REGIME_MAP[$customer->regime_type] ?? 49,
            'municipality_id' => $customer->dian_municipality_id ?? self::DEFAULT_MUNICIPALITY_ID,
        ];

        if (! empty($customer->dv) && $customer->dv !== 'NULL') {
            $payload['dv'] = (int) $customer->dv;
        }
        if (! empty($customer->phone)) {
            $payload['phone'] = $customer->phone;
        }
        if (! empty($customer->address)) {
            $payload['address'] = $customer->address;
        }

        return $payload;
    }

    protected function buildPaymentForms(SaleInvoice $invoice): array
    {
        $isCredit = ($invoice->payment_status === SaleInvoice::PAYMENT_PENDIENTE && $invoice->payments->isEmpty())
            || $invoice->payment_status === SaleInvoice::PAYMENT_PARCIAL;

        $dueDate = $invoice->due_date?->format('Y-m-d') ?? $invoice->date?->format('Y-m-d') ?? now()->format('Y-m-d');

        // Sin pagos registrados → contado/crédito según payment_terms_days
        if ($invoice->payments->isEmpty()) {
            return [[
                'payment_form_id' => $invoice->payment_terms_days > 0 ? 2 : 1,
                'payment_method_id' => 10, // 10 = Efectivo (fallback)
                'payment_due_date' => $dueDate,
                'duration_measure' => (string) ($invoice->payment_terms_days ?: 0),
                'payment_amount' => number_format((float) $invoice->net_payable, 2, '.', ''),
            ]];
        }

        // Una entrada por cada pago registrado (Envío 2 soporta multi-pago)
        $forms = [];
        foreach ($invoice->payments as $payment) {
            $forms[] = [
                'payment_form_id' => $isCredit ? 2 : 1,
                'payment_method_id' => $this->mapPaymentMethodToDian($payment->payment_method),
                'payment_due_date' => $dueDate,
                'duration_measure' => '0',
                'payment_amount' => number_format((float) $payment->amount, 2, '.', ''),
            ];
        }

        return $forms;
    }

    /**
     * Mapping de nuestro payment_method (string) → DIAN payment_method_id.
     * Lista DIAN canónica: 10=Efectivo, 20=Cheque, 30=Transferencia,
     * 42=Consignación, 47=Transf débito bancaria, 48=Tarjeta crédito,
     * 49=Tarjeta débito.
     */
    protected function mapPaymentMethodToDian(string $method): int
    {
        return match ($method) {
            'cash' => 10,
            'check' => 20,
            'bank_transfer' => 30,
            'credit_card' => 48,
            'debit_card' => 49,
            'electronic' => 47,
            'credit_note' => 10,  // sin código DIAN específico, default
            'other' => 10,
            default => 10,
        };
    }

    protected function buildDocumentAllowanceCharges(SaleInvoice $invoice): array
    {
        if ((float) $invoice->discount_total <= 0) {
            return [];
        }

        return [[
            'discount_id' => 10,
            'charge_indicator' => false,
            'allowance_charge_reason' => 'DESCUENTO GENERAL',
            'amount' => number_format((float) $invoice->discount_total, 2, '.', ''),
            'base_amount' => number_format((float) $invoice->subtotal, 2, '.', ''),
        ]];
    }

    /**
     * Agrupa impuestos NO-retención por tasa para tax_totals del documento.
     */
    protected function buildTaxTotals(SaleInvoice $invoice): array
    {
        $grouped = [];

        foreach ($invoice->lines as $line) {
            if ((float) $line->tax_amount <= 0 || ! $line->tax) {
                continue;
            }

            $taxId = self::TAX_ID_MAP[$line->tax->type] ?? 1;
            // Solo no-retenciones aquí
            if (in_array($taxId, [5, 6, 7], true)) {
                continue;
            }

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

    /**
     * Retenciones (with_holding_tax_total) — agrupadas por tax_id + tasa.
     */
    protected function buildWithHoldingTaxTotal(SaleInvoice $invoice): array
    {
        $grouped = [];

        foreach ($invoice->retentions as $ret) {
            $taxId = self::TAX_ID_MAP[$ret->tax_type] ?? null;
            if (! $taxId || ! in_array($taxId, [5, 6, 7], true)) {
                continue;
            }

            $key = $taxId.'_'.$ret->rate;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'tax_id' => $taxId,
                    'tax_amount' => 0.0,
                    'percent' => number_format((float) $ret->rate, 4, '.', ''),
                    'taxable_amount' => 0.0,
                ];
            }

            $grouped[$key]['tax_amount'] += (float) $ret->amount;
            $grouped[$key]['taxable_amount'] += (float) $ret->base_amount;
        }

        return collect(array_values($grouped))->map(fn ($g) => [
            'tax_id' => $g['tax_id'],
            'tax_amount' => number_format($g['tax_amount'], 2, '.', ''),
            'percent' => $g['percent'],
            'taxable_amount' => number_format($g['taxable_amount'], 2, '.', ''),
        ])->all();
    }

    protected function buildLegalMonetaryTotals(SaleInvoice $invoice): array
    {
        $lineExtension = (float) $invoice->subtotal - (float) $invoice->discount_total;
        $taxExclusive = $lineExtension;
        $taxInclusive = $lineExtension + (float) $invoice->tax_total;

        return [
            'line_extension_amount' => number_format($lineExtension, 2, '.', ''),
            'tax_exclusive_amount' => number_format($taxExclusive, 2, '.', ''),
            'tax_inclusive_amount' => number_format($taxInclusive, 2, '.', ''),
            'allowance_total_amount' => number_format((float) $invoice->discount_total, 2, '.', ''),
            'charge_total_amount' => '0.00',
            'payable_amount' => number_format((float) ($invoice->net_payable ?: $invoice->total), 2, '.', ''),
        ];
    }

    protected function buildInvoiceLines(SaleInvoice $invoice): array
    {
        $lines = [];

        foreach ($invoice->lines as $line) {
            $unitMeasureId = $this->mapUnitMeasure($line->product?->unit_of_measure);

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

            // Descuento por línea
            if ((float) $line->discount_amount > 0) {
                $linePayload['allowance_charges'] = [[
                    'discount_id' => 10,
                    'charge_indicator' => false,
                    'allowance_charge_reason' => 'Descuento línea',
                    'amount' => number_format((float) $line->discount_amount, 2, '.', ''),
                    'base_amount' => number_format((float) $line->subtotal, 2, '.', ''),
                ]];
            }

            // Tax totals por línea (IVA, INC)
            if ((float) $line->tax_amount > 0 && $line->tax) {
                $taxId = self::TAX_ID_MAP[$line->tax->type] ?? 1;
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

    protected function mapUnitMeasure(?string $unit): int
    {
        return self::UNIT_MEASURE_MAP[$unit] ?? 70;
    }
}
