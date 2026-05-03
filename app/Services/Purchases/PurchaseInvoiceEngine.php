<?php

namespace App\Services\Purchases;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de Facturas de Compra:
 *  - post(): genera InventoryMovements + JournalEntry, marca posted
 *  - addPayment(): crea Payment + JournalEntry, recalcula payment_status
 *  - cancel(): reversa todo (TODO en próxima iteración)
 *
 * Patrón replicable después para SalesInvoice (Fase 3).
 */
class PurchaseInvoiceEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $numberer,
    ) {}

    /**
     * Contabiliza la factura: crea inventario por cada línea con producto + asiento contable.
     */
    public function post(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new RuntimeException('Solo se puede contabilizar una factura en estado borrador.');
        }

        $invoice->load(['lines.product', 'lines.tax', 'supplier', 'location']);

        if ($invoice->lines->isEmpty()) {
            throw new RuntimeException('La factura no tiene líneas.');
        }

        return DB::transaction(function () use ($invoice) {
            $this->recalculateTotals($invoice);

            // 1. Inventory movements por cada línea con producto que controla inventario
            foreach ($invoice->lines as $line) {
                if (! $line->product_id || ! $line->product?->track_inventory) {
                    continue;
                }

                // Costo unitario para inventario = unit_cost neto (sin IVA descontable)
                $movement = $this->inventory->addMovement(
                    $line->product,
                    $invoice->location,
                    [
                        'type' => 'purchase',
                        'quantity' => (float) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                        'date' => $invoice->date,
                        'reference_type' => PurchaseInvoice::class,
                        'reference_id' => $invoice->id,
                        'reference_number' => $invoice->fullNumber(),
                        'third_party_id' => $invoice->third_party_id,
                        'description' => "Compra {$invoice->fullNumber()} — {$invoice->supplier->name}",
                    ]
                );

                $line->update(['inventory_movement_id' => $movement->id]);
            }

            // 2. Journal entry: DR Inventario por línea + DR IVA descontable | CR CxP
            $journalEntry = $this->createPurchaseJournalEntry($invoice);

            // 3. Marcar posted
            $invoice->update([
                'status' => PurchaseInvoice::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => auth()->id(),
                'journal_entry_id' => $journalEntry->id,
                'payment_status' => PurchaseInvoice::PAYMENT_PENDIENTE,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Registra un pago contra una factura contabilizada.
     * Crea Payment + JournalEntry (DR CxP | CR Caja/Banco) + recalcula payment_status.
     */
    public function addPayment(PurchaseInvoice $invoice, array $data): Payment
    {
        if (! $invoice->isPosted()) {
            throw new RuntimeException('Solo se pueden registrar pagos sobre facturas contabilizadas.');
        }

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor a 0.');
        }

        $balance = (float) $invoice->balance;
        if ($amount > $balance + 0.01) {
            throw new RuntimeException(sprintf(
                'El pago de $%s excede el saldo pendiente de $%s.',
                number_format($amount, 2),
                number_format($balance, 2),
            ));
        }

        return DB::transaction(function () use ($invoice, $data, $amount) {
            $cashAccountId = $data['account_id'];
            $payableAccountId = $invoice->supplier?->default_payable_account_id
                ?? Account::withoutGlobalScopes()
                    ->where('company_id', $invoice->company_id)
                    ->where('code', '220505')
                    ->value('id');

            if (! $payableAccountId) {
                throw new RuntimeException('No se encontró la cuenta por pagar (220505 o configurada en el proveedor).');
            }

            // 1. Asiento contable del pago
            $entry = $this->createPaymentJournalEntry(
                $invoice,
                $payableAccountId,
                $cashAccountId,
                $amount,
                $data['date'],
                $data['payment_method'],
                $data['reference'] ?? null,
            );

            // 2. Payment record
            $payment = Payment::create([
                'company_id' => $invoice->company_id,
                'paymentable_type' => PurchaseInvoice::class,
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

    /**
     * Recalcula totales de la factura desde sus líneas.
     */
    public function recalculateTotals(PurchaseInvoice $invoice): void
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

        $invoice->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => $total,
        ]);
    }

    /**
     * Recalcula paid_amount y actualiza payment_status según el saldo.
     */
    public function recomputePaymentStatus(PurchaseInvoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->total;

        if ($paid <= 0.0) {
            $status = PurchaseInvoice::PAYMENT_PENDIENTE;
        } elseif ($paid + 0.01 < $total) {
            $status = PurchaseInvoice::PAYMENT_PARCIAL;
        } else {
            $status = PurchaseInvoice::PAYMENT_PAGADO;
        }

        // Vencido si está pendiente/parcial Y due_date pasó
        if (
            in_array($status, [PurchaseInvoice::PAYMENT_PENDIENTE, PurchaseInvoice::PAYMENT_PARCIAL], true)
            && $invoice->due_date
            && $invoice->due_date->isPast()
        ) {
            $status = PurchaseInvoice::PAYMENT_VENCIDO;
        }

        $invoice->update([
            'paid_amount' => $paid,
            'payment_status' => $status,
        ]);
    }

    /**
     * Genera el asiento contable de la factura de compra.
     * DR cuenta_inventario_producto por cada línea (subtotal sin IVA)
     * DR IVA descontable (240810) por la suma de impuestos
     * CR Cuenta por pagar del proveedor (220505 default) por el total
     */
    protected function createPurchaseJournalEntry(PurchaseInvoice $invoice): JournalEntry
    {
        $vatAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '240810')
            ->value('id');

        $defaultExpenseAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '6135')
            ->value('id');

        $payableAccountId = $invoice->supplier?->default_payable_account_id
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $invoice->company_id)
                ->where('code', '220505')
                ->value('id');

        if (! $payableAccountId) {
            throw new RuntimeException('Falta cuenta por pagar (220505 o configurar en el proveedor).');
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => $invoice->date,
            'type' => 'purchase',
            'reference' => $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Compra {$invoice->fullNumber()} — {$invoice->supplier->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => auth()->id(),
            'created_by_user_id' => auth()->id(),
            'total_debit' => $invoice->total,
            'total_credit' => $invoice->total,
        ]);

        $line = 1;

        // DR por cada línea con producto: usa la cuenta de inventario del producto
        foreach ($invoice->lines as $invLine) {
            $accountId = $invLine->account_id
                ?? $invLine->product?->inventory_account_id
                ?? $defaultExpenseAccountId;

            if (! $accountId) {
                throw new RuntimeException(
                    "Línea sin cuenta contable. Configura la cuenta de inventario del producto '{$invLine->description}' o asigna manualmente."
                );
            }

            $debitAmount = (float) $invLine->subtotal - (float) $invLine->discount_amount;

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $accountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => "Línea {$invLine->line_number}: {$invLine->description}",
                'debit' => $debitAmount,
                'credit' => 0,
            ]);
        }

        // DR IVA descontable (sumado)
        if ((float) $invoice->tax_total > 0) {
            if (! $vatAccountId) {
                throw new RuntimeException('Falta cuenta IVA descontable (240810) en el PUC.');
            }
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $vatAccountId,
                'third_party_id' => $invoice->third_party_id,
                'description' => 'IVA descontable',
                'debit' => $invoice->tax_total,
                'credit' => 0,
            ]);
        }

        // CR Cuenta por pagar (total)
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => $line,
            'account_id' => $payableAccountId,
            'third_party_id' => $invoice->third_party_id,
            'description' => "Cuenta por pagar {$invoice->supplier->name}",
            'debit' => 0,
            'credit' => $invoice->total,
        ]);

        return $entry;
    }

    /**
     * Genera el asiento contable de un pago.
     * DR Cuenta por pagar | CR Caja/Banco
     */
    protected function createPaymentJournalEntry(
        PurchaseInvoice $invoice,
        int $payableAccountId,
        int $cashAccountId,
        float $amount,
        string $date,
        string $method,
        ?string $reference,
    ): JournalEntry {
        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'CE'); // CE = Comprobante de Egreso

        $methodLabel = Payment::PAYMENT_METHODS[$method] ?? $method;

        $entry = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'CE',
            'number' => $number,
            'date' => $date,
            'type' => 'payment',
            'reference' => $reference ?: $invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Pago {$methodLabel} a {$invoice->supplier->name} — Factura {$invoice->fullNumber()}",
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
            'account_id' => $payableAccountId,
            'third_party_id' => $invoice->third_party_id,
            'description' => "Pago factura {$invoice->fullNumber()}",
            'debit' => $amount,
            'credit' => 0,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'line_number' => 2,
            'account_id' => $cashAccountId,
            'description' => "Salida {$methodLabel}",
            'debit' => 0,
            'credit' => $amount,
        ]);

        return $entry;
    }
}
