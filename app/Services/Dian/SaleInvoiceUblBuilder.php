<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\Dian\Resolution;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Support\DianDvCalculator;

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
     * Mapping de ThirdParty::document_type → type_document_identification_id.
     *
     * OJO: son los IDs del catalogo de apidian, NO los codigos oficiales del
     * anexo tecnico DIAN (donde CC=13 y NIT=31). apidian lleva su propia tabla
     * numerada de 1 a 11 y rechaza los codigos DIAN con
     * "customer.type document identification id es invalido".
     * El catalogo completo se consulta con POST /reports/master/database.
     */
    public const DOCUMENT_TYPE_MAP = [
        'cc' => 3,         // Cédula de ciudadanía
        'ce' => 5,         // Cédula de extranjería
        'ti' => 2,         // Tarjeta de identidad
        'nit' => 6,        // NIT
        'pasaporte' => 7,  // Pasaporte
        'rut' => 6,        // RUT (mismo que NIT)
        'nuip' => 11,      // NUIP
        'die' => 8,        // Documento de identificación extranjero
    ];

    /**
     * Mapping de ThirdParty::regime_type → type_regime_id.
     *
     * Igual que el mapa de documentos: apidian usa 1 y 2, no los codigos 48/49
     * del anexo DIAN, y devuelve "customer.type regime id es invalido" si le
     * llegan esos.
     */
    public const REGIME_MAP = [
        'comun' => 1,                  // Responsable de IVA
        'gran_contribuyente' => 1,     // Responsable de IVA (gran contribuyente)
        'no_responsable_iva' => 2,     // No responsable de IVA
        'simplificado' => 2,           // Régimen simplificado = No responsable
    ];

    /** Fallback de regimen cuando el tercero no lo tiene definido. */
    public const DEFAULT_REGIME_ID = 2;

    /** type_discount_id con el que el proveedor identifica la propina. */
    public const TIP_CHARGE_ID = 4;

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
            // Solo pedimos el envio de correo cuando hay a quien mandarselo:
            // el consumidor final no tiene correo y apidian falla en silencio.
            'sendmail' => ! $this->isFinalConsumer($invoice->customer)
                && filter_var($invoice->customer?->email, FILTER_VALIDATE_EMAIL) !== false,
        ];

        $document['customer'] = $this->buildCustomer($invoice->customer);
        $document['payment_form'] = $this->buildPaymentForms($invoice);

        // Sin descuentos de documento: todos los de este modelo son de linea
        // (SaleInvoiceEngine::recalculateTotals arma discount_total sumando
        // line.discount_amount) y ya viajan en el allowance_charges de cada
        // linea. Lo unico que va aqui es la propina, que DIAN recibe como
        // CARGO (charge_indicator = true), no como descuento.
        $charges = $this->buildTipCharge($invoice);
        if ($charges !== []) {
            $document['allowance_charges'] = $charges;
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

    /**
     * Consumidor final: apidian espera SOLO tres campos (identificacion,
     * nombre y matricula mercantil). Si se le mandan ademas tipo de documento,
     * regimen, organizacion o municipio, los valida y rechaza la factura con
     * "customer.type document identification id es invalido" — para el
     * consumidor final esos atributos no aplican.
     *
     * El numero es 222222222222 (doce dos) segun DIAN, aunque internamente el
     * tercero se crea con 222222222.
     */
    public const FINAL_CONSUMER_DOCUMENT = '222222222222';

    /**
     * ¿Es el tercero generico de mostrador? Se reconoce por un documento
     * compuesto solo de dos, sin importar cuantos, porque distintos modulos
     * lo sembraron con longitudes diferentes.
     */
    protected function isFinalConsumer(?ThirdParty $customer): bool
    {
        $document = preg_replace('/\D/', '', (string) $customer?->document_number);

        return $document !== '' && strlen($document) >= 9 && trim($document, '2') === '';
    }

    protected function buildCustomer(?ThirdParty $customer): array
    {
        if (! $customer || $this->isFinalConsumer($customer)) {
            return [
                'identification_number' => self::FINAL_CONSUMER_DOCUMENT,
                'name' => 'CONSUMIDOR FINAL',
                'merchant_registration' => '0000000-00',
            ];
        }

        $payload = [
            'identification_number' => $customer->document_number,
            'name' => $customer->name,
            'email' => filter_var($customer->email, FILTER_SANITIZE_EMAIL) ?: 'sin@email.com',
            'merchant_registration' => '0000000-00',
            'type_document_identification_id' => self::DOCUMENT_TYPE_MAP[$customer->document_type] ?? self::DOCUMENT_TYPE_MAP['cc'],
            'type_organization_id' => $customer->person_type === 'juridica' ? 1 : 2,
            'type_liability_id' => self::DEFAULT_LIABILITY_ID,
            'type_regime_id' => self::REGIME_MAP[$customer->regime_type] ?? self::DEFAULT_REGIME_ID,
            'municipality_id' => $customer->dian_municipality_id ?? self::DEFAULT_MUNICIPALITY_ID,
        ];

        if (DianDvCalculator::hasValue($customer->dv)) {
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

    /**
     * Agrupa impuestos NO-retención por tasa para tax_totals del documento.
     */
    /**
     * ¿Esta linea declara impuesto ante DIAN?
     *
     * Es EL criterio: lo usan el tax_totals de la linea, el consolidado del
     * documento y el tax_exclusive_amount. Si los tres no coinciden, DIAN
     * rechaza con FAU04 ("Base Imponible es distinto a la suma de los valores
     * de las bases imponibles de todas lineas de detalle").
     *
     * Basta con que la linea tenga un impuesto asignado: una linea exenta
     * (0%) tambien se declara, con percent 0. Solo quedan fuera las lineas
     * sin impuesto y las retenciones, que van en with_holding_tax_total.
     */
    protected function lineDeclaresTax($line): bool
    {
        if (! $line->tax) {
            return false;
        }

        return ! in_array(self::TAX_ID_MAP[$line->tax->type] ?? 1, [5, 6, 7], true);
    }

    protected function buildTaxTotals(SaleInvoice $invoice): array
    {
        $grouped = [];

        foreach ($invoice->lines as $line) {
            if (! $this->lineDeclaresTax($line)) {
                continue;
            }

            $taxId = self::TAX_ID_MAP[$line->tax->type] ?? 1;

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

    /**
     * Totales del documento. DIAN valida la coherencia aritmetica y no
     * recalcula nada, asi que todo se suma desde las lineas en vez de leer
     * los agregados de la factura.
     */
    /**
     * La propina viaja como cargo del documento. base_amount es el total
     * facturado sobre el que se calculo, tal como lo espera el proveedor.
     */
    protected function buildTipCharge(SaleInvoice $invoice): array
    {
        $tip = round((float) $invoice->tip_amount, 2);

        if ($tip <= 0) {
            return [];
        }

        return [[
            'type_discount_id' => self::TIP_CHARGE_ID,
            'charge_indicator' => true,
            'allowance_charge_reason' => 'propina',
            'amount' => number_format($tip, 2, '.', ''),
            'base_amount' => number_format((float) $invoice->total, 2, '.', ''),
        ]];
    }

    protected function buildLegalMonetaryTotals(SaleInvoice $invoice): array
    {
        $lineExtension = 0.0;
        $taxExclusive = 0.0;
        $taxTotal = 0.0;

        foreach ($invoice->lines as $line) {
            $base = (float) $line->subtotal - (float) $line->discount_amount;
            $lineExtension += $base;
            $taxTotal += (float) $line->tax_amount;

            // Solo las lineas que declaran impuesto suman a la base gravable.
            // Incluir aqui una linea sin impuesto es lo que dispara FAU04.
            if ($this->lineDeclaresTax($line)) {
                $taxExclusive += $base;
            }
        }

        $taxInclusive = $lineExtension + $taxTotal;

        // La propina es un cargo: no toca las bases ni los impuestos, pero si
        // el total a pagar. Por eso no entra en tax_inclusive.
        $charges = round((float) $invoice->tip_amount, 2);

        return [
            'line_extension_amount' => number_format($lineExtension, 2, '.', ''),
            'tax_exclusive_amount' => number_format($taxExclusive, 2, '.', ''),
            'tax_inclusive_amount' => number_format($taxInclusive, 2, '.', ''),
            // Los descuentos de este modelo son SIEMPRE de linea: ya estan
            // restados del line_extension y declarados en el allowance_charges
            // de cada linea. Reportarlos otra vez aqui los contaria dos veces
            // y rompe la formula de arriba.
            'allowance_total_amount' => '0.00',
            'charge_total_amount' => number_format($charges, 2, '.', ''),
            // payable = tax_inclusive + cargos - descuentos - anticipos.
            // Las retenciones NO lo reducen: viajan en with_holding_tax_total.
            'payable_amount' => number_format($taxInclusive + $charges, 2, '.', ''),
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

            // Tax totals por línea (IVA, INC). Las exentas tambien se declaran.
            if ($this->lineDeclaresTax($line)) {
                $linePayload['tax_totals'] = [[
                    'tax_id' => self::TAX_ID_MAP[$line->tax->type] ?? 1,
                    'tax_amount' => number_format((float) $line->tax_amount, 2, '.', ''),
                    'percent' => (float) $line->tax_rate,
                    'taxable_amount' => number_format((float) $line->subtotal - (float) $line->discount_amount, 2, '.', ''),
                ]];
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
