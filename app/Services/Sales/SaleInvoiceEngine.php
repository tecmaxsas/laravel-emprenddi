<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\SaleInvoice;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de facturas de venta. Pareja del PurchaseInvoiceEngine.
 *
 *  - post(): salida de inventario + asiento contable (ventas + IVA generado +
 *    COGS + descarga de inventario), reserva consecutivo DIAN si la sede
 *    tiene resolución activa, marca posted.
 *  - addPayment(): comprobante de ingreso (DR Caja/Banco | CR CxC).
 *  - cancel(): TODO próxima iteración.
 */
class SaleInvoiceEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $numberer,
    ) {}

    /**
     * Contabiliza la factura de venta.
     */
    public function post(SaleInvoice $invoice): SaleInvoice
    {
        if ($invoice->status !== SaleInvoice::STATUS_DRAFT) {
            throw new RuntimeException('Solo se puede contabilizar una factura en estado borrador.');
        }

        $invoice->load(['lines.product', 'lines.tax', 'retentions.tax', 'customer', 'location']);

        if ($invoice->lines->isEmpty()) {
            throw new RuntimeException('La factura no tiene líneas.');
        }

        return DB::transaction(function () use ($invoice) {
            $this->recalculateTotals($invoice);

            // 1. Si la sede tiene resolución DIAN activa, tomamos el consecutivo
            //    del rango autorizado y sobreescribimos el número interno + prefijo.
            //    Esto pasa antes de crear movimientos para que la referencia
            //    en el inventory_movement use el número final.
            $this->reserveDianNumberIfApplicable($invoice);

            // 2. Salida de inventario + acumulación del costo total para COGS
            $totalCogs = 0;

            foreach ($invoice->lines as $line) {
                if (! $line->product_id || ! $line->product?->track_inventory) {
                    continue;
                }

                $movement = $this->inventory->addMovement(
                    $line->product,
                    $invoice->location,
                    [
                        'type' => 'sale',
                        'quantity' => (float) $line->quantity,
                        'date' => $invoice->date,
                        'reference_type' => SaleInvoice::class,
                        'reference_id' => $invoice->id,
                        'reference_number' => $invoice->fullNumber(),
                        'third_party_id' => $invoice->third_party_id,
                        'description' => "Venta {$invoice->fullNumber()} — {$invoice->customer->name}",
                    ]
                );

                // Snapshot del costo (WAvg al momento) en la línea para reportes de margen.
                $line->update([
                    'inventory_movement_id' => $movement->id,
                    'cost_at_sale' => $movement->unit_cost,
                ]);

                $totalCogs += abs((float) $movement->quantity) * (float) $movement->unit_cost;
            }

            // 3. Asiento contable de la venta (ingresos + IVA + CxC)
            $journalEntry = $this->createSaleJournalEntry($invoice);

            // 4. Asiento contable del costo de ventas (DR 6135 | CR 1435) si hubo inventario
            if ($totalCogs > 0) {
                $this->createCogsJournalEntry($invoice, $totalCogs);
            }

            // 5. Marcar posted
            $invoice->update([
                'status' => SaleInvoice::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => auth()->id(),
                'journal_entry_id' => $journalEntry->id,
                'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Registra un pago entrante contra la factura (cliente paga).
     * Crea Payment + JournalEntry (DR Caja/Banco | CR CxC) + recalcula payment_status.
     */
    public function addPayment(SaleInvoice $invoice, array $data): Payment
    {
        if (! $invoice->isPosted()) {
            throw new RuntimeException('Solo se pueden registrar pagos sobre facturas contabilizadas.');
        }

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor a 0.');
        }

        // balance ya considera net_payable (no total), porque getBalanceAttribute
        // del modelo descuenta retenciones. Las retenciones nunca son saldo
        // pendiente del cliente.
        $balance = (float) $invoice->balance;
        if ($amount > $balance + 0.01) {
            throw new RuntimeException(sprintf(
                'El pago de $%s excede el saldo pendiente de $%s (neto a pagar después de retenciones).',
                number_format($amount, 2),
                number_format($balance, 2),
            ));
        }

        return DB::transaction(function () use ($invoice, $data, $amount) {
            $cashAccountId = $data['account_id'];
            $receivableAccountId = $invoice->customer?->default_receivable_account_id
                ?? Account::withoutGlobalScopes()
                    ->where('company_id', $invoice->company_id)
                    ->where('code', '1305')
                    ->value('id');

            if (! $receivableAccountId) {
                throw new RuntimeException('No se encontró la cuenta por cobrar (1305 o configurada en el cliente).');
            }

            // 1. Asiento del pago: DR Caja/Banco | CR CxC
            $entry = $this->createPaymentJournalEntry(
                $invoice,
                $cashAccountId,
                $receivableAccountId,
                $amount,
                $data['date'],
                $data['payment_method'],
                $data['reference'] ?? null,
            );

            // 2. Payment record
            $payment = Payment::create([
                'company_id' => $invoice->company_id,
                'paymentable_type' => SaleInvoice::class,
                'paymentable_id' => $invoice->id,
                'third_party_id' => $invoice->third_party_id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'account_id' => $cashAccountId,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'journal_entry_id' => $entry->id,
                'created_by_user_id' => auth()->id(),
            ]);

            // 3. Recalcular paid_amount + payment_status
            $this->recomputePaymentStatus($invoice);

            return $payment;
        });
    }

    public function recalculateTotals(SaleInvoice $invoice): void
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($invoice->lines as $line) {
            $subtotal += (float) $line->subtotal;
            $discount += (float) $line->discount_amount;
            $tax += (float) $line->tax_amount;
            $total += (float) $line->total;
        }

        $retentionTotal = (float) $invoice->retentions->sum('amount');

        $invoice->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'retention_total' => $retentionTotal,
            'total' => $total,
            'net_payable' => $total - $retentionTotal,
        ]);
    }

    public function recomputePaymentStatus(SaleInvoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        // El status de pago se compara contra net_payable (ya descontadas las
        // retenciones), no contra total. Las retenciones son anticipos de
        // impuestos y no son un saldo pendiente del cliente.
        $netPayable = (float) ($invoice->net_payable ?: $invoice->total);

        if ($paid <= 0.0) {
            $status = SaleInvoice::PAYMENT_PENDIENTE;
        } elseif ($paid + 0.01 < $netPayable) {
            $status = SaleInvoice::PAYMENT_PARCIAL;
        } else {
            $status = SaleInvoice::PAYMENT_PAGADO;
        }

        if (
            in_array($status, [SaleInvoice::PAYMENT_PENDIENTE, SaleInvoice::PAYMENT_PARCIAL], true)
            && $invoice->due_date
            && $invoice->due_date->isPast()
        ) {
            $status = SaleInvoice::PAYMENT_VENCIDO;
        }

        $invoice->update([
            'paid_amount' => $paid,
            'payment_status' => $status,
        ]);
    }

    /**
     * Si la sede tiene LocationResolution activa para Factura Electrónica,
     * reserva atómicamente el siguiente consecutivo y reescribe prefix+number
     * de la factura. Si no hay resolución, el número manual se mantiene.
     */
    protected function reserveDianNumberIfApplicable(SaleInvoice $invoice): void
    {
        $assignment = $invoice->location->activeResolution(documentTypeId: 1);
        if (! $assignment) {
            return;
        }

        $resolution = $assignment->resolution;
        $next = $assignment->reserveNextNumber();

        if ($next > $resolution->range_to) {
            throw new RuntimeException(sprintf(
                'Consecutivo agotado para la resolución %s (rango %s-%s). Solicita uno nuevo a DIAN antes de seguir facturando.',
                $resolution->resolution_number ?: '?',
                number_format($resolution->range_from),
                number_format($resolution->range_to),
            ));
        }

        $invoice->update([
            'prefix' => $resolution->prefix ?: $invoice->prefix,
            'number' => $next,
        ]);

        // refresh in-memory para que createSaleJournalEntry use el número nuevo
        $invoice->refresh();
        $invoice->load(['lines.product', 'lines.tax', 'customer', 'location']);
    }

    /**
     * Asiento contable de la venta:
     *   DR 1305 (CxC) — total
     *   CR 4135 (ingresos) — base imponible neta por línea
     *   CR 240805 (IVA generado) — suma de impuestos
     */
    protected function createSaleJournalEntry(SaleInvoice $invoice): JournalEntry
    {
        $vatAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '240805')
            ->value('id');

        $defaultIncomeAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '4135')
            ->value('id');

        $receivableAccountId = $invoice->customer?->default_receivable_account_id
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $invoice->company_id)
                ->where('code', '1305')
                ->value('id');

        if (! $receivableAccountId) {
            throw new RuntimeException('Falta cuenta por cobrar (1305 o configurar en el cliente).');
        }
        if (! $defaultIncomeAccountId) {
            throw new RuntimeException('Falta cuenta de ingresos por ventas (4135) en el PUC.');
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $invoice->date,
            'type' => 'sale',
            'reference' => $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Venta {$invoice->fullNumber()} — {$invoice->customer->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => auth()->id(),
            'created_by_user_id' => auth()->id(),
            'total_debit' => $invoice->total,
            'total_credit' => $invoice->total,
        ]);

        $netPayable = (float) ($invoice->net_payable ?: $invoice->total);
        $line = 1;

        // DR CxC por el NETO a pagar (total − retenciones).
        // Las retenciones se debitan aparte a sus cuentas (1355xx) — el
        // resultado es: total = CxC + sum(retenciones).
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $line++,
            'account_id' => $receivableAccountId,
            'third_party_id' => $invoice->third_party_id,
            'description' => "CxC {$invoice->customer->name}",
            'debit' => $netPayable,
            'credit' => 0,
        ]);

        // DR por cada retención que el cliente nos hizo (anticipo de impuesto).
        foreach ($invoice->retentions as $ret) {
            $accountId = $ret->tax?->sale_account_id;
            if (! $accountId) {
                throw new RuntimeException(sprintf(
                    'La retención "%s" no tiene cuenta de venta configurada (Tax::sale_account_id). Configúrala en Contabilidad → Impuestos.',
                    $ret->tax_name,
                ));
            }
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $accountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => "Anticipo {$ret->tax_name}",
                'debit' => $ret->amount,
                'credit' => 0,
            ]);
        }

        // CR ingresos por cada línea (subtotal - descuento, sin IVA)
        foreach ($invoice->lines as $invLine) {
            $accountId = $invLine->account_id ?? $defaultIncomeAccountId;
            $netAmount = (float) $invLine->subtotal - (float) $invLine->discount_amount;

            if ($netAmount <= 0) {
                continue;
            }

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $accountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => "Línea {$invLine->line_number}: {$invLine->description}",
                'debit' => 0,
                'credit' => $netAmount,
            ]);
        }

        // CR IVA generado
        if ((float) $invoice->tax_total > 0) {
            if (! $vatAccountId) {
                throw new RuntimeException('Falta cuenta IVA generado (240805) en el PUC.');
            }
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line,
                'account_id' => $vatAccountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => 'IVA generado',
                'debit' => 0,
                'credit' => $invoice->tax_total,
            ]);
        }

        return $entry;
    }

    /**
     * Asiento del costo de ventas:
     *   DR 6135 (Costo de ventas) — al WAvg de salida
     *   CR 1435 (Inventario) — mismo monto
     * Se crea como asiento separado para que sea fácil aislar costos en
     * reportes y para mantener los movimientos de inventario referenciados
     * a un asiento contable distinto del de la venta.
     */
    protected function createCogsJournalEntry(SaleInvoice $invoice, float $totalCogs): JournalEntry
    {
        $cogsAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '6135')
            ->value('id');

        $inventoryAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '1435')
            ->value('id');

        if (! $cogsAccountId || ! $inventoryAccountId) {
            // Sin cuentas configuradas, omitimos el asiento de COGS pero ya
            // grabamos los movimientos de inventario (los saldos van bien).
            // Reportes contables quedarán inconsistentes hasta que se configuren.
            return $this->dummyEntry();
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $invoice->date,
            'type' => 'cogs',
            'reference' => $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Costo de ventas {$invoice->fullNumber()}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => auth()->id(),
            'created_by_user_id' => auth()->id(),
            'total_debit' => $totalCogs,
            'total_credit' => $totalCogs,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 1,
            'account_id' => $cogsAccountId,
            'description' => "Costo {$invoice->fullNumber()}",
            'debit' => $totalCogs,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 2,
            'account_id' => $inventoryAccountId,
            'description' => "Salida inventario {$invoice->fullNumber()}",
            'debit' => 0,
            'credit' => $totalCogs,
        ]);

        return $entry;
    }

    /**
     * Comprobante de ingreso: DR Caja/Banco | CR CxC.
     */
    protected function createPaymentJournalEntry(
        SaleInvoice $invoice,
        int $cashAccountId,
        int $receivableAccountId,
        float $amount,
        string $date,
        string $method,
        ?string $reference,
    ): JournalEntry {
        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'CI'); // CI = Comprobante de Ingreso

        $methodLabel = Payment::PAYMENT_METHODS[$method] ?? $method;

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'CI',
            'number' => $number,
            'date' => $date,
            'type' => 'receipt',
            'reference' => $reference ?: $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Pago {$methodLabel} de {$invoice->customer->name} — Factura {$invoice->fullNumber()}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => auth()->id(),
            'created_by_user_id' => auth()->id(),
            'total_debit' => $amount,
            'total_credit' => $amount,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 1,
            'account_id' => $cashAccountId,
            'description' => "Ingreso {$methodLabel}",
            'debit' => $amount,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 2,
            'account_id' => $receivableAccountId,
            'third_party_id' => $invoice->third_party_id,
            'description' => "Pago factura {$invoice->fullNumber()}",
            'debit' => 0,
            'credit' => $amount,
        ]);

        return $entry;
    }

    protected function dummyEntry(): JournalEntry
    {
        // No-op para mantener el tipo de retorno cuando faltan cuentas COGS.
        return new JournalEntry();
    }
}
