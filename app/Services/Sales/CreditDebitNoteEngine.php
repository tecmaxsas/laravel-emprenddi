<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Postea NC/ND y genera asientos contables (signo invertido según type).
 *
 * Nota Crédito (revierte la venta):
 *   DR 4135 Ingresos             (subtotal-dto)
 *   DR 240805 IVA generado       (tax_total)
 *      CR 1305 CxC del cliente   (total)
 *   + Si affects_inventory:
 *     DR 1435 Inventario        (cost_at_return × qty)
 *        CR 6135 COGS           (mismo monto)
 *
 * Nota Débito (aumenta la cuenta del cliente):
 *   DR 1305 CxC                  (total)
 *      CR 4135 Ingresos          (subtotal-dto)
 *      CR 240805 IVA generado    (tax_total)
 */
class CreditDebitNoteEngine
{
    public function __construct(
        protected InventoryEngine $inventory,
        protected JournalEntryNumberer $numberer,
        protected DocumentNumberer $documentNumberer,
    ) {}

    public function post(CreditDebitNote $note): CreditDebitNote
    {
        if (! $note->isDraft()) {
            throw new RuntimeException('Solo se puede postear una NC/ND en borrador.');
        }

        $note->load(['lines.product', 'lines.tax', 'customer', 'location', 'saleInvoice']);

        if ($note->lines->isEmpty()) {
            throw new RuntimeException('La nota no tiene líneas.');
        }

        return DB::transaction(function () use ($note) {
            $this->recalculateTotals($note);

            // 1. Consecutivo de la resolución de notas de la EMPRESA (NC=2,
            //    ND=3). No se busca por sede: ver reserveDianNumberIfApplicable.
            //    Sin resolución, la nota conserva su número manual.
            $this->reserveDianNumberIfApplicable($note);

            // 2. Inventario solo en NC con flag affects_inventory (devolución física).
            $totalCogsReversal = 0;
            if ($note->isCredit() && $note->affects_inventory) {
                foreach ($note->lines as $line) {
                    if (! $line->product_id || ! $line->product?->track_inventory) {
                        continue;
                    }

                    // Costo: el snapshoteado en cost_at_return o si no, WAvg actual.
                    $costAtReturn = (float) ($line->cost_at_return ?: 0);

                    $movement = $this->inventory->addMovement(
                        $line->product,
                        $note->location,
                        [
                            'type' => 'return_from_customer',
                            'quantity' => (float) $line->quantity,
                            'unit_cost' => $costAtReturn,
                            'date' => $note->date,
                            'reference_type' => CreditDebitNote::class,
                            'reference_id' => $note->id,
                            'reference_number' => $note->fullNumber(),
                            'third_party_id' => $note->third_party_id,
                            'description' => "Devolución NC {$note->fullNumber()} — {$note->customer->name}",
                        ]
                    );

                    $line->update([
                        'inventory_movement_id' => $movement->id,
                        'cost_at_return' => $movement->unit_cost,
                    ]);

                    $totalCogsReversal += abs((float) $movement->quantity) * (float) $movement->unit_cost;
                }
            }

            // 3. Asiento contable invertido según type.
            $journalEntry = $this->createJournalEntry($note);

            // 4. Reversa COGS si hubo devolución de inventario (NC con affects_inventory).
            // El método agrupa por par (cuenta_costo, cuenta_inventario) que
            // resuelve cada producto vía cascada categoría → PUC default.
            if ($totalCogsReversal > 0) {
                $this->createCogsReversalEntry($note);
            }

            $note->update([
                'status' => CreditDebitNote::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $journalEntry->id,
                'dian_status' => $note->dian_status ?: CreditDebitNote::DIAN_PENDING,
            ]);

            return $note->fresh();
        });
    }

    /**
     * Le pone resolución DIAN a una nota que se contabilizó sin ella.
     *
     * Existe para rescatar las notas que quedaron numeradas a mano mientras el
     * motor las buscaba por la sede. Sin esto habría que anularlas y rehacerlas,
     * y una nota crédito anulada deja la cuenta del cliente donde no debe.
     *
     * Renumerar un documento contabilizado no es gratis, así que solo procede
     * cuando la DIAN nunca lo aceptó: si lo aceptó, ese número ya existe fuera
     * de aquí y cambiarlo dejaría los dos sistemas hablando de documentos
     * distintos. El asiento se arrastra con la nota, porque su referencia es
     * justamente el número que cambia.
     *
     * @throws RuntimeException con un mensaje que se le puede mostrar al usuario
     */
    public function asignarResolucionDian(CreditDebitNote $note): CreditDebitNote
    {
        if (! $note->isPosted()) {
            throw new RuntimeException('La nota todavía no está contabilizada.');
        }

        if ($note->dian_status === CreditDebitNote::DIAN_ACCEPTED) {
            throw new RuntimeException(
                'La DIAN ya aceptó esta nota con su número actual. Cambiarlo dejaría '
                .'a Emprenddi y a la DIAN hablando de documentos distintos.'
            );
        }

        if ($note->dian_resolution_id) {
            throw new RuntimeException('Esta nota ya tiene resolución asignada.');
        }

        $docTypeId = $note->isCredit() ? 2 : 3;

        return DB::transaction(function () use ($note, $docTypeId) {
            $reserva = $this->documentNumberer->reserveGlobal(
                $note->company_id,
                $docTypeId,
                'credit_debit_notes',
            );

            if (! $reserva) {
                throw new RuntimeException(sprintf(
                    'La empresa no tiene una resolución activa de %s. Cárgala en '
                    .'Configuración → DIAN → Resoluciones. No hay que asignarla a '
                    .'ninguna sede: la numeración de notas es de toda la empresa.',
                    $note->isCredit() ? 'nota crédito' : 'nota débito',
                ));
            }

            $anterior = $note->fullNumber();

            $note->update([
                'prefix' => $reserva['prefix'] ?: $note->prefix,
                'number' => $reserva['number'],
                'dian_resolution_id' => $reserva['resolution_id'],
                'dian_status' => CreditDebitNote::DIAN_PENDING,
                'dian_error_message' => null,
            ]);

            $note->refresh();

            // El asiento referencia el número de la nota: si no se mueve con
            // ella, el libro apunta a un documento que ya no existe.
            if ($note->journal_entry_id) {
                JournalEntry::withoutGlobalScopes()
                    ->whereKey($note->journal_entry_id)
                    ->update(['reference' => $note->fullNumber()]);
            }

            JournalEntry::withoutGlobalScopes()
                ->where('company_id', $note->company_id)
                ->where('reference', $anterior)
                ->update(['reference' => $note->fullNumber()]);

            return $note->fresh();
        });
    }

    public function recalculateTotals(CreditDebitNote $note): void
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;

        foreach ($note->lines as $line) {
            $subtotal += (float) $line->subtotal;
            $discount += (float) $line->discount_amount;
            $tax += (float) $line->tax_amount;
            $total += (float) $line->total;
        }

        $note->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'total' => $total,
        ]);
    }

    /**
     * Toma el consecutivo de la resolución de notas de la empresa.
     *
     * **La resolución de notas no se asigna a una sede.** La DIAN autoriza los
     * rangos de facturación por establecimiento —cada punto de venta factura con
     * el suyo—, pero las notas crédito y débito llevan un solo consecutivo para
     * toda la empresa, lo emita quien lo emita.
     *
     * Antes se buscaba por la sede. Como nadie asigna una resolución de notas a
     * una sede —no tiene por qué hacerlo—, nunca la encontraba, salía en
     * silencio y la nota se quedaba con su número manual y sin resolución. El
     * daño aparecía después, al enviarla: el proveedor respondía «La resolución
     * no está configurada» y no había manera de relacionar ese mensaje con una
     * asignación que nadie sabía que hacía falta.
     *
     * Si la empresa no tiene resolución de notas, la nota conserva su número
     * manual. Es válido: sirve para el control interno y para la contabilidad,
     * solo que no se puede transmitir. La pantalla lo dice.
     */
    protected function reserveDianNumberIfApplicable(CreditDebitNote $note): void
    {
        // Tipo de documento DIAN: 2=Nota Crédito, 3=Nota Débito.
        $docTypeId = $note->isCredit() ? 2 : 3;

        $reserva = $this->documentNumberer->reserveGlobal(
            $note->company_id,
            $docTypeId,
            'credit_debit_notes',
        );

        if (! $reserva) {
            return;
        }

        $note->update([
            'prefix' => $reserva['prefix'] ?: $note->prefix,
            'number' => $reserva['number'],
            'dian_resolution_id' => $reserva['resolution_id'],
            'dian_status' => CreditDebitNote::DIAN_PENDING,
        ]);

        $note->refresh();
        $note->load(['lines.product', 'lines.tax', 'customer', 'location', 'saleInvoice']);
    }

    /**
     * Asiento principal — invertido según type.
     */
    protected function createJournalEntry(CreditDebitNote $note): JournalEntry
    {
        $vatAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '240805')
            ->value('id');

        $defaultIncomeAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '4135')
            ->value('id');

        $receivableAccountId = $note->customer?->default_receivable_account_id
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $note->company_id)
                ->where('code', '1305')
                ->value('id');

        if (! $receivableAccountId) {
            throw new RuntimeException('Falta cuenta por cobrar (1305).');
        }
        if (! $defaultIncomeAccountId) {
            throw new RuntimeException('Falta cuenta de ingresos (4135) en el PUC.');
        }

        $company = Company::find($note->company_id);
        $entryPrefix = $note->isCredit() ? 'NC' : 'ND';
        $number = $this->numberer->next($company, $entryPrefix);

        $entry = JournalEntry::create([
            'company_id' => $note->company_id,
            'prefix' => $entryPrefix,
            'number' => $number,
            'date' => $note->date,
            'type' => $note->isCredit() ? 'credit_note' : 'debit_note',
            'reference' => $note->fullNumber(),
            'third_party_id' => $note->third_party_id,
            'description' => sprintf(
                '%s %s — %s (factura %s)',
                $note->typeLabel(),
                $note->fullNumber(),
                $note->customer->name,
                $note->saleInvoice?->fullNumber() ?? '?',
            ),
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $note->total,
            'total_credit' => $note->total,
        ]);

        $line = 1;
        $isCredit = $note->isCredit();

        if ($isCredit) {
            // NC: revierte ingresos (DR), revierte IVA (DR), revierte CxC (CR)
            // DR ingresos por línea
            foreach ($note->lines as $invLine) {
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
                    'third_party_id' => $note->third_party_id,
                    'description' => "Reversa línea {$invLine->line_number}: {$invLine->description}",
                    'debit' => $netAmount,
                    'credit' => 0,
                ]);
            }

            // DR IVA generado (revierte)
            if ((float) $note->tax_total > 0 && $vatAccountId) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line++,
                    'account_id' => $vatAccountId,
                    'third_party_id' => $note->third_party_id,
                    'description' => 'Reversa IVA generado',
                    'debit' => $note->tax_total,
                    'credit' => 0,
                ]);
            }

            // CR CxC (cliente debe menos)
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line,
                'account_id' => $receivableAccountId,
                'third_party_id' => $note->third_party_id,
                'description' => "Reversa CxC {$note->customer->name}",
                'debit' => 0,
                'credit' => $note->total,
            ]);
        } else {
            // ND: aumenta CxC (DR), genera más ingreso/IVA (CR)
            // DR CxC
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line++,
                'account_id' => $receivableAccountId,
                'third_party_id' => $note->third_party_id,
                'description' => "Cargo adicional CxC {$note->customer->name}",
                'debit' => $note->total,
                'credit' => 0,
            ]);

            // CR ingresos por línea
            foreach ($note->lines as $invLine) {
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
                    'third_party_id' => $note->third_party_id,
                    'description' => "Línea {$invLine->line_number}: {$invLine->description}",
                    'debit' => 0,
                    'credit' => $netAmount,
                ]);
            }

            // CR IVA
            if ((float) $note->tax_total > 0 && $vatAccountId) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $line,
                    'account_id' => $vatAccountId,
                    'third_party_id' => $note->third_party_id,
                    'description' => 'IVA generado adicional',
                    'debit' => 0,
                    'credit' => $note->tax_total,
                ]);
            }
        }

        return $entry;
    }

    /**
     * Reversa de COGS por devolución física (solo NC con affects_inventory).
     * Por cada par único (cuenta_costo, cuenta_inventario) resuelto vía
     * cascada producto → categoría → PUC default (6135 / 1435), emite UNA
     * línea DR (inventario, reingresa) y UNA línea CR (costo, reversa).
     *
     * Es simétrico al asiento original de COGS de la venta, asegurando
     * que cualquier categoría con cuentas distintas quede balanceada en
     * la reversa.
     */
    protected function createCogsReversalEntry(CreditDebitNote $note): ?JournalEntry
    {
        $defaultCogsAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '6135')
            ->value('id');

        $defaultInventoryAccountId = Account::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('code', '1435')
            ->value('id');

        // Acumular costo a reversar por par (cogs_account_id, inventory_account_id)
        $pairs = [];
        $totalCogs = 0.0;

        foreach ($note->lines as $line) {
            if (! $line->product_id || ! $line->product?->track_inventory) {
                continue;
            }
            $cost = abs((float) $line->quantity) * (float) ($line->cost_at_return ?? 0);
            if ($cost <= 0) {
                continue;
            }

            $cogsId = $line->product?->effectiveCostAccountId() ?? $defaultCogsAccountId;
            $invId = $line->product?->effectiveInventoryAccountId() ?? $defaultInventoryAccountId;

            if (! $cogsId || ! $invId) {
                continue;
            }

            $key = "{$cogsId}:{$invId}";
            if (! isset($pairs[$key])) {
                $pairs[$key] = ['cogs' => (int) $cogsId, 'inv' => (int) $invId, 'amount' => 0.0];
            }
            $pairs[$key]['amount'] += $cost;
            $totalCogs += $cost;
        }

        if ($totalCogs <= 0 || empty($pairs)) {
            return null;
        }

        $company = Company::find($note->company_id);
        $number = $this->numberer->next($company, 'NC');

        $entry = JournalEntry::create([
            'company_id' => $note->company_id,
            'prefix' => 'NC',
            'number' => $number,
            'date' => $note->date,
            'type' => 'cogs_reversal',
            'reference' => $note->fullNumber(),
            'third_party_id' => $note->third_party_id,
            'description' => "Reversa costo NC {$note->fullNumber()}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => round($totalCogs, 2),
            'total_credit' => round($totalCogs, 2),
        ]);

        $lineNum = 1;
        // DR cuenta de inventario por cada par (reingresa stock contable)
        foreach ($pairs as $p) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $p['inv'],
                'description' => "Reingreso inventario {$note->fullNumber()}",
                'debit' => round($p['amount'], 2),
                'credit' => 0,
            ]);
        }
        // CR cuenta de costo por cada par (reversa COGS)
        foreach ($pairs as $p) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $lineNum++,
                'account_id' => $p['cogs'],
                'description' => "Reversa COGS {$note->fullNumber()}",
                'debit' => 0,
                'credit' => round($p['amount'], 2),
            ]);
        }

        return $entry;
    }
}
