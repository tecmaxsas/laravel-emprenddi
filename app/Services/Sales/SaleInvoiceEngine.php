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
use App\Support\CommissionsSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Motor de facturas de venta. Pareja del PurchaseInvoiceEngine.
 *
 *  - post(): salida de inventario + asiento contable (ventas + IVA generado +
 *    COGS + descarga de inventario), reserva consecutivo DIAN si la sede
 *    tiene resolución activa, marca posted.
 *  - addPayment(): comprobante de ingreso (DR Caja/Banco | CR CxC).
 *  - cancel(): devuelve inventario, reversa el asiento de ventas y el de
 *    COGS si aplica. Bloqueada si la factura tiene pagos o ya fue enviada
 *    a la DIAN (en ese caso se debe emitir una nota crédito).
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

            // 1. El consecutivo YA viene reservado por DocumentNumberer desde
            //    quien crea la factura (POS, restaurante, parqueadero, alta
            //    manual). Aqui solo se marca el estado DIAN inicial.
            $this->markPendingDianIfApplicable($invoice);

            // 2. Salida de inventario + acumulación del costo total para COGS.
            // Si la factura viene de una remisión (from_delivery_note_id != null),
            // el inventario YA salió en el dispatch — no generamos movimientos
            // nuevos, solo sumamos los costos heredados para el COGS.
            $totalCogs = 0;
            $cameFromDelivery = $invoice->from_delivery_note_id !== null;

            foreach ($invoice->lines as $line) {
                if (! $line->product_id || ! $line->product?->track_inventory) {
                    continue;
                }

                if ($cameFromDelivery && $line->inventory_movement_id) {
                    // Heredó el movimiento del despacho — usamos su costo para COGS.
                    $totalCogs += abs((float) $line->quantity) * (float) ($line->cost_at_sale ?? 0);
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

                // Seriales: si el producto los maneja, marcar como sold y
                // vincular a esta línea para que la garantía sea consultable.
                if ($line->product->tracks_serials) {
                    $this->markSerialsSoldForLine($invoice, $line);
                }

                $totalCogs += abs((float) $movement->quantity) * (float) $movement->unit_cost;
            }

            // 3. Asiento contable de la venta (ingresos + IVA + CxC)
            $journalEntry = $this->createSaleJournalEntry($invoice);

            // 4. Asiento del costo de ventas si hubo movimiento de inventario.
            // El método agrupa por par (cuenta_costo, cuenta_inventario) que
            // resuelve cada producto vía cascada categoría → PUC default.
            if ($totalCogs > 0) {
                $this->createCogsJournalEntry($invoice);
            }

            // 5. Marcar posted. dian_status='pending' indica "lista para envío"
            // (independiente de si tiene resolución asignada — el envío manual
            // verificará y rechazará si falta config).
            $invoice->update([
                'status' => SaleInvoice::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $journalEntry->id,
                'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
                'dian_status' => $invoice->dian_status ?: SaleInvoice::DIAN_PENDING,
            ]);

            // 6. Comisiones — causar al facturar si el modo es 'invoiced'.
            // (modo 'collected' se causa en recomputePaymentStatus al quedar pagada)
            $this->maybeCauseCommission($invoice->fresh(), CommissionsSettings::CAUSATION_INVOICED);

            return $invoice->fresh();
        });
    }

    /**
     * Causa la comisión del vendedor si el módulo está activo y el modo de
     * causación configurado coincide con $forBasis. Defensivo: un error
     * aquí no debe tumbar la venta (solo se loguea).
     */
    protected function maybeCauseCommission(SaleInvoice $invoice, string $forBasis): void
    {
        if (! CommissionsSettings::moduleActive()) return;
        if (CommissionsSettings::causation() !== $forBasis) return;

        try {
            app(\App\Services\Commissions\CommissionEngine::class)
                ->causeForInvoice($invoice, $forBasis);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo causar comisión', [
                'invoice_id' => $invoice->id,
                'basis' => $forBasis,
                'error' => $e->getMessage(),
            ]);
        }
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

            // 2. Payment record — hereda la sesión de caja del invoice
            // para que el cierre de caja agregue este ingreso al turno correcto.
            $payment = Payment::create([
                'company_id' => $invoice->company_id,
                'paymentable_type' => SaleInvoice::class,
                'paymentable_id' => $invoice->id,
                'third_party_id' => $invoice->third_party_id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'account_id' => $cashAccountId,
                'cash_register_session_id' => $invoice->cash_register_session_id,
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

        // Comisiones — causar al cobrar cuando la factura queda totalmente
        // pagada (modo 'collected'). El engine es idempotente: si ya se causó
        // (ej. recompute corre varias veces) no duplica.
        if ($status === SaleInvoice::PAYMENT_PAGADO) {
            $this->maybeCauseCommission($invoice->fresh(), CommissionsSettings::CAUSATION_COLLECTED);
        }
    }

    /**
     * Marca la factura electronica como pendiente de envio a DIAN.
     *
     * Antes este metodo ademas reservaba un consecutivo y reescribia
     * prefix+number, de cuando el numero se asignaba al contabilizar. Hoy
     * TODOS los caminos que crean una factura (POS, restaurante, parqueadero
     * y el alta manual) piden el numero a DocumentNumberer antes de crearla,
     * asi que aquella reserva era una segunda: se quemaba un consecutivo por
     * factura (del 8544 saltaba al 8546) y ademas podia renumerar con la
     * resolucion equivocada, porque activeResolution() no distingue POS de
     * electronica.
     */
    protected function markPendingDianIfApplicable(SaleInvoice $invoice): void
    {
        if ($invoice->isPosInvoice() || $invoice->dian_status) {
            return;
        }

        $invoice->update(['dian_status' => SaleInvoice::DIAN_PENDING]);
        $invoice->refresh();
        $invoice->load(['lines.product', 'lines.tax', 'retentions.tax', 'customer', 'location']);
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
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
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

        // CR ingresos por cada línea (subtotal - descuento, sin IVA).
        // Cuenta de venta: override de línea → cuenta del producto → cuenta
        // de la categoría → 4135 (PUC default).
        foreach ($invoice->lines as $invLine) {
            $accountId = $invLine->account_id
                ?? $invLine->product?->effectiveSaleAccountId()
                ?? $defaultIncomeAccountId;
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
     * Asiento del costo de ventas. Por cada par único de cuentas
     * (costo, inventario) resuelto en cascada producto → categoría →
     * PUC default (6135 / 1435), emite UNA línea DR (costo) y UNA línea
     * CR (inventario) con el total acumulado de ese par.
     *
     * Si todos los productos comparten las mismas cuentas (caso típico),
     * el asiento sigue siendo 1 DR + 1 CR como antes. Si los productos
     * pertenecen a categorías con cuentas distintas (ej. mercancía 6135
     * vs servicios 6155), el asiento tiene N DR + N CR balanceados.
     */
    protected function createCogsJournalEntry(SaleInvoice $invoice): JournalEntry
    {
        $defaultCogsAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '6135')
            ->value('id');

        $defaultInventoryAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->where('code', '1435')
            ->value('id');

        // Acumular costo por par (cogs_account_id, inventory_account_id)
        $pairs = []; // "cogsId:invId" => ['cogs' => int, 'inv' => int, 'amount' => float]
        $totalCogs = 0.0;

        foreach ($invoice->lines as $line) {
            if (! $line->product_id || ! $line->product?->track_inventory) {
                continue;
            }
            $cost = abs((float) $line->quantity) * (float) ($line->cost_at_sale ?? 0);
            if ($cost <= 0) continue;

            $cogsId = $line->product?->effectiveCostAccountId() ?? $defaultCogsAccountId;
            $invId = $line->product?->effectiveInventoryAccountId() ?? $defaultInventoryAccountId;

            // Si una cuenta no resuelve por ningún lado, omitimos esa línea
            // del asiento (saldos de inventario quedan correctos por los
            // inventory_movements; reportes contables omitirán esa parte).
            if (! $cogsId || ! $invId) continue;

            $key = "{$cogsId}:{$invId}";
            if (! isset($pairs[$key])) {
                $pairs[$key] = ['cogs' => (int) $cogsId, 'inv' => (int) $invId, 'amount' => 0.0];
            }
            $pairs[$key]['amount'] += $cost;
            $totalCogs += $cost;
        }

        if ($totalCogs <= 0 || empty($pairs)) {
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
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => round($totalCogs, 2),
            'total_credit' => round($totalCogs, 2),
        ]);

        $lineNum = 1;
        // DR cuenta de costo por cada par único
        foreach ($pairs as $p) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $p['cogs'],
                'description' => "Costo {$invoice->fullNumber()}",
                'debit' => round($p['amount'], 2),
                'credit' => 0,
            ]);
        }
        // CR cuenta de inventario por cada par único (mismos montos)
        foreach ($pairs as $p) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $p['inv'],
                'description' => "Salida inventario {$invoice->fullNumber()}",
                'debit' => 0,
                'credit' => round($p['amount'], 2),
            ]);
        }

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
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
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

    /**
     * Anula una factura de venta contabilizada: devuelve el inventario,
     * crea asiento de reversa de la venta y, si hubo COGS, también
     * reversa el asiento de costo de ventas.
     *
     * Bloqueada si la factura tiene pagos registrados o si ya fue
     * enviada o aceptada por la DIAN (en ese caso debe usarse una
     * nota crédito).
     */
    public function cancel(SaleInvoice $invoice): SaleInvoice
    {
        if ($invoice->status !== SaleInvoice::STATUS_POSTED) {
            throw new RuntimeException('Solo puedes anular una factura contabilizada.');
        }
        if ($invoice->status === SaleInvoice::STATUS_CANCELLED) {
            throw new RuntimeException('Esta factura ya está anulada.');
        }
        if ((float) $invoice->paid_amount > 0) {
            throw new RuntimeException(
                'No puedes anular una factura con pagos registrados. Reversa primero los pagos.'
            );
        }
        if (in_array($invoice->dian_status, [SaleInvoice::DIAN_SENT, SaleInvoice::DIAN_ACCEPTED], true)) {
            throw new RuntimeException(
                $invoice->dian_status === SaleInvoice::DIAN_ACCEPTED
                    ? 'Esta factura ya fue aceptada por la DIAN. Para anularla emite una nota crédito en su lugar.'
                    : 'Esta factura fue enviada a la DIAN y está pendiente de respuesta. No puedes anularla hasta recibir el resultado.'
            );
        }

        $invoice->load(['lines.product', 'customer', 'location', 'journalEntry.lines']);

        return DB::transaction(function () use ($invoice) {
            // 1. Devuelve el inventario (return_from_customer — entra al stock).
            foreach ($invoice->lines as $line) {
                if (! $line->product_id || ! $line->product?->track_inventory) {
                    continue;
                }
                if (! $line->inventory_movement_id) {
                    continue; // No salió inventario al postear.
                }
                $this->inventory->addMovement(
                    $line->product,
                    $invoice->location,
                    [
                        'type' => 'return_from_customer',
                        'quantity' => (float) $line->quantity,
                        'unit_cost' => (float) ($line->cost_at_sale ?? 0),
                        'date' => now(),
                        'reference_type' => SaleInvoice::class,
                        'reference_id' => $invoice->id,
                        'reference_number' => 'ANUL-'.$invoice->fullNumber(),
                        'third_party_id' => $invoice->third_party_id,
                        'description' => "Anulación venta {$invoice->fullNumber()}",
                    ]
                );

                // Libera seriales vendidos por esta línea: vuelven a in_stock
                // y se desvinculan de la línea para poder vender de nuevo.
                if ($line->product->tracks_serials) {
                    \App\Models\ProductSerial::query()
                        ->where('sale_invoice_line_id', $line->id)
                        ->where('status', \App\Models\ProductSerial::STATUS_SOLD)
                        ->update([
                            'status' => \App\Models\ProductSerial::STATUS_IN_STOCK,
                            'sale_invoice_line_id' => null,
                            'sold_at' => null,
                        ]);
                }
            }

            // 2. Reversa del asiento de venta.
            $this->createReversalFrom(
                $invoice,
                $invoice->journalEntry,
                'reversal',
                "Anulación venta {$invoice->fullNumber()} — {$invoice->customer->name}",
            );

            // 3. Reversa del asiento de COGS si existe (se busca por type+reference).
            $cogsEntry = JournalEntry::query()
                ->where('company_id', $invoice->company_id)
                ->where('type', 'cogs')
                ->where('reference', $invoice->fullNumber())
                ->with('lines')
                ->first();
            if ($cogsEntry) {
                $this->createReversalFrom(
                    $invoice,
                    $cogsEntry,
                    'cogs_reversal',
                    "Reversa COGS de venta {$invoice->fullNumber()}",
                );
            }

            // 4. Marca la factura como anulada.
            $invoice->update([
                'status' => SaleInvoice::STATUS_CANCELLED,
                'payment_status' => SaleInvoice::PAYMENT_CANCELADA,
            ]);

            // 5. Reversa comisiones pendientes de esta factura (las ya
            // liquidadas no se tocan — se ajustan en la siguiente liquidación).
            if (CommissionsSettings::moduleActive()) {
                try {
                    app(\App\Services\Commissions\CommissionEngine::class)->reverseForInvoice($invoice);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('No se pudo reversar comisión al anular factura', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $invoice->fresh();
        });
    }

    /**
     * Construye un asiento de reversa a partir de uno existente:
     * intercambia débito y crédito de cada línea para anular el efecto.
     */
    protected function createReversalFrom(
        SaleInvoice $invoice,
        ?JournalEntry $original,
        string $type,
        string $description,
    ): JournalEntry {
        if (! $original) {
            throw new RuntimeException('No se encontró el asiento original a reversar.');
        }

        $company = Company::find($invoice->company_id);
        $number = $this->numberer->next($company, 'AS');

        $reversal = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $number,
            'date' => now()->toDateString(),
            'type' => $type,
            'reference' => 'ANUL-'.$invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => $description,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $original->total_credit,
            'total_credit' => $original->total_debit,
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
     * Marca como sold los seriales de una línea de venta. Valida que
     * cada serial esté in_stock antes de pasarlo a sold y vincularlo
     * con la línea (para que después se pueda buscar la garantía).
     */
    protected function markSerialsSoldForLine(SaleInvoice $invoice, \App\Models\SaleInvoiceLine $line): void
    {
        $raw = $line->serials ?? [];
        $serials = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) $s),
            is_array($raw) ? $raw : [],
        ))));

        $qty = (int) round((float) $line->quantity);
        if (count($serials) !== $qty) {
            throw new RuntimeException(sprintf(
                'Producto "%s": vendes %d unidades pero hay %d seriales asignados. Cada unidad debe tener su serial.',
                $line->product?->name ?? 'producto',
                $qty,
                count($serials),
            ));
        }

        $records = \App\Models\ProductSerial::query()
            ->where('company_id', $invoice->company_id)
            ->where('product_id', $line->product_id)
            ->whereIn('serial_number', $serials)
            ->get();

        $found = $records->pluck('serial_number')->all();
        $missing = array_diff($serials, $found);
        if (! empty($missing)) {
            throw new RuntimeException(
                'No se encontraron estos seriales: '.implode(', ', $missing)
            );
        }

        $notInStock = $records->where('status', '!=', \App\Models\ProductSerial::STATUS_IN_STOCK)
            ->pluck('serial_number')->all();
        if (! empty($notInStock)) {
            throw new RuntimeException(
                'Estos seriales no están disponibles para venta (ya vendidos o ajustados): '.implode(', ', $notInStock)
            );
        }

        foreach ($records as $serial) {
            $serial->update([
                'status' => \App\Models\ProductSerial::STATUS_SOLD,
                'sale_invoice_line_id' => $line->id,
                'sold_at' => $invoice->date ?? now(),
            ]);
        }
    }
}
