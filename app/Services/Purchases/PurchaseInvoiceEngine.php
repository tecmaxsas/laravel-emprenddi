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
use App\Support\CashSessionGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de Facturas de Compra:
 *  - post(): genera InventoryMovements + JournalEntry, marca posted
 *  - addPayment(): crea Payment + JournalEntry, recalcula payment_status
 *  - cancel(): devuelve inventario y crea asiento de reversa
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

                // Seriales: si el producto los maneja, crear un ProductSerial
                // por cada string capturado en la línea. La validación
                // qty == count(serials) se hace acá (no en el form) para que
                // también atrape ediciones via API y la UI no pueda saltarla.
                if ($line->product->tracks_serials) {
                    $this->createSerialsForPurchaseLine($invoice, $line);
                }
            }

            // 2. Journal entry: DR Inventario por línea + DR IVA descontable | CR CxP
            $journalEntry = $this->createPurchaseJournalEntry($invoice);

            // 3. Marcar posted
            $invoice->update([
                'status' => PurchaseInvoice::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
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

        // Egreso real de caja: requiere un turno abierto para que el cierre
        // de caja del cajero refleje este pago. Mismo principio que ventas.
        $session = CashSessionGate::requireOpenSession();

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

        return DB::transaction(function () use ($invoice, $data, $amount, $session) {
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

            // 2. Payment record — atado a la sesión actual del cajero
            // para que CashSessionSummary lo cuente como egreso del turno.
            $payment = Payment::create([
                'company_id' => $invoice->company_id,
                'paymentable_type' => PurchaseInvoice::class,
                'paymentable_id' => $invoice->id,
                'third_party_id' => $invoice->third_party_id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'account_id' => $cashAccountId,
                'cash_register_session_id' => $session->id,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'journal_entry_id' => $entry->id,
                'created_by_user_id' => Auth::id(),
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
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $invoice->total,
            'total_credit' => $invoice->total,
        ]);

        $line = 1;

        // DR por cada línea con producto: usa la cuenta de inventario del producto
        foreach ($invoice->lines as $invLine) {
            $accountId = $invLine->account_id
                ?? $invLine->product?->effectiveInventoryAccountId()
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
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
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

    /**
     * Anula una factura contabilizada: devuelve el inventario al
     * proveedor y crea un asiento de reversa que invierte el efecto
     * contable del original. Bloqueada si la factura tiene pagos
     * registrados — los pagos deben reversarse primero.
     */
    public function cancel(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if (! $invoice->isPosted()) {
            throw new RuntimeException('Solo puedes anular una factura contabilizada.');
        }
        if ($invoice->isCancelled()) {
            throw new RuntimeException('Esta factura ya está anulada.');
        }
        if ((float) $invoice->paid_amount > 0) {
            throw new RuntimeException(
                'No puedes anular una factura con pagos registrados. Reversa primero los pagos.'
            );
        }

        $invoice->load(['lines.product', 'supplier', 'location', 'journalEntry.lines']);

        // Bloqueo previo: si algún serial entrado por esta compra ya se vendió,
        // no podemos anular sin dejar inconsistente la venta. El usuario debe
        // anular las ventas primero (que restablecerán los seriales).
        $soldSerials = \App\Models\ProductSerial::query()
            ->whereIn('purchase_invoice_line_id', $invoice->lines->pluck('id'))
            ->where('status', \App\Models\ProductSerial::STATUS_SOLD)
            ->exists();
        if ($soldSerials) {
            throw new RuntimeException(
                'No puedes anular: hay seriales entrados por esta compra que ya se vendieron. '
                .'Anula primero esas ventas para liberar los seriales.'
            );
        }

        return DB::transaction(function () use ($invoice) {
            // 1. Devuelve el inventario (return_to_supplier — salida del stock).
            foreach ($invoice->lines as $line) {
                if (! $line->product_id || ! $line->product?->track_inventory) {
                    continue;
                }
                $this->inventory->addMovement(
                    $line->product,
                    $invoice->location,
                    [
                        'type' => 'return_to_supplier',
                        'quantity' => (float) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                        'date' => now(),
                        'reference_type' => PurchaseInvoice::class,
                        'reference_id' => $invoice->id,
                        'reference_number' => 'ANUL-'.$invoice->fullNumber(),
                        'third_party_id' => $invoice->third_party_id,
                        'description' => "Anulación compra {$invoice->fullNumber()}",
                    ]
                );

                // Soft-delete de los seriales que entraron por esta línea
                // (todos están in_stock — los vendidos los bloquea el guard
                // de arriba). Quedan en deleted_at para auditoría.
                if ($line->product->tracks_serials) {
                    \App\Models\ProductSerial::query()
                        ->where('purchase_invoice_line_id', $line->id)
                        ->where('status', \App\Models\ProductSerial::STATUS_IN_STOCK)
                        ->delete();
                }
            }

            // 2. Asiento de reversa contable.
            $this->createReversalJournalEntry($invoice);

            // 3. Marca la factura como anulada.
            $invoice->update([
                'status' => PurchaseInvoice::STATUS_CANCELLED,
                'payment_status' => PurchaseInvoice::PAYMENT_CANCELADA,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Crea un asiento de reversa: intercambia débito y crédito del
     * asiento original para que el efecto contable neto sea cero.
     */
    protected function createReversalJournalEntry(PurchaseInvoice $invoice): JournalEntry
    {
        $original = $invoice->journalEntry;
        if (! $original) {
            throw new RuntimeException('La factura no tiene asiento contable que reversar.');
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $reversal = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => now()->toDateString(),
            'type' => 'reversal',
            'reference' => 'ANUL-'.$invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => "Anulación compra {$invoice->fullNumber()} — {$invoice->supplier->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $invoice->total,
            'total_credit' => $invoice->total,
        ]);

        $line = 1;
        foreach ($original->lines as $origLine) {
            JournalEntryLine::create([
                'journal_entry_id' => $reversal->id,
                'line_number' => $line++,
                'account_id' => $origLine->account_id,
                'third_party_id' => $origLine->third_party_id,
                'description' => "Reversa: {$origLine->description}",
                'debit' => $origLine->credit,
                'credit' => $origLine->debit,
            ]);
        }

        return $reversal;
    }

    /**
     * Crea un ProductSerial in_stock por cada string de la línea. Valida
     * cantidad y unicidad dentro de la empresa antes de insertar.
     */
    protected function createSerialsForPurchaseLine(PurchaseInvoice $invoice, PurchaseInvoiceLine $line): void
    {
        $raw = $line->serials ?? [];
        $serials = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) $s),
            is_array($raw) ? $raw : [],
        ))));

        $qty = (int) round((float) $line->quantity);
        if (count($serials) !== $qty) {
            throw new RuntimeException(sprintf(
                'La línea de "%s" tiene cantidad %d pero capturaste %d seriales. Deben coincidir.',
                $line->product?->name ?? 'producto',
                $qty,
                count($serials),
            ));
        }

        // Choque con seriales ya existentes en la empresa (otro producto u otra
        // compra). El unique (company_id, serial_number) lo atrapa al insert
        // pero damos un error más útil acá.
        $existing = \App\Models\ProductSerial::query()
            ->where('company_id', $invoice->company_id)
            ->whereIn('serial_number', $serials)
            ->pluck('serial_number')
            ->all();
        if (! empty($existing)) {
            throw new RuntimeException(
                'Los siguientes seriales ya existen en tu inventario y no pueden duplicarse: '
                .implode(', ', $existing)
            );
        }

        $now = now();
        foreach ($serials as $serial) {
            \App\Models\ProductSerial::create([
                'company_id' => $invoice->company_id,
                'product_id' => $line->product_id,
                'location_id' => $invoice->location_id,
                'serial_number' => $serial,
                'status' => \App\Models\ProductSerial::STATUS_IN_STOCK,
                'purchase_invoice_line_id' => $line->id,
                'received_at' => $invoice->date ?? $now,
            ]);
        }
    }
}
