<?php

namespace App\Services\Sales;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\SaleInvoice;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Cash\CashSessionSummary;
use App\Services\Inventory\InventoryEngine;
use App\Support\DefaultAccounts;
use App\Support\PaymentAccountResolver;
use App\Support\PaymentMethodOptions;
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
        protected CashSessionSummary $caja,
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

        $this->exigirQueQuepaEnLaFactura($note);
        $this->exigirQueLaPlataAlcance($note);

        return DB::transaction(function () use ($note) {
            $this->recalculateTotals($note);

            // 1. Consecutivo de la resolución de notas de la EMPRESA. No se
            //    busca por sede: ver reserveDianNumberIfApplicable. Sin
            //    resolución, la nota conserva su número manual.
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
                'dian_status' => $note->viajaALaDian()
                    ? ($note->dian_status ?: CreditDebitNote::DIAN_PENDING)
                    : null,
            ]);

            // 5. La factura tiene que enterarse. Sin esto el asiento acredita
            //    la CxC pero el saldo sigue completo, y el cliente que
            //    devolvio la mercancia sigue apareciendo debiendola.
            $this->refrescarAcreditadoDe($note->saleInvoice);

            return $note->fresh();
        });
    }

    /**
     * Si se le devuelve plata al cliente, el cajon tiene que tenerla.
     *
     * Un cajero no puede entregar $80.000 de un cajon con $50.000, y el
     * sistema tampoco deberia dejarlo registrar: lo que queda despues no es un
     * error de digitacion, es un arqueo que nunca pudo ser cierto. Pregunta lo
     * mismo que el arqueo —`CashSessionSummary`— para que el POS y el cierre
     * no puedan contradecirse.
     */
    protected function exigirQueLaPlataAlcance(CreditDebitNote $note): void
    {
        if (! $note->devuelvePlata()) {
            return;
        }

        if (! PaymentMethodOptions::esEfectivo((string) $note->refund_method, (int) $note->company_id)) {
            // Una devolucion por transferencia o tarjeta no sale del cajon.
            return;
        }

        $sesion = $note->session;

        if (! $sesion || $sesion->status !== CashRegisterSession::STATUS_OPEN) {
            throw new RuntimeException(
                'Para devolver efectivo tiene que haber un turno de caja abierto: esa plata sale '
                .'del cajon y tiene que quedar en el arqueo de alguien.'
            );
        }

        $disponible = (float) $this->caja->compute($sesion)['expected_cash'];
        $monto = round((float) $note->refund_amount, 2);

        if ($monto > $disponible + 0.01) {
            throw new RuntimeException(sprintf(
                'En la caja hay $%s y la devolucion es de $%s. Registra primero la entrada que '
                .'falta, o devuelve por otro medio.',
                number_format(max(0, $disponible), 0, ',', '.'),
                number_format($monto, 0, ',', '.'),
            ));
        }
    }

    /**
     * Recalcula cuanto se le ha acreditado a una factura.
     *
     * Se suma desde las notas en vez de ir acumulando, para que recalcular dos
     * veces de el mismo resultado: un acumulador que se incrementa es un
     * acumulador que algun dia se incrementa dos veces.
     */
    public function refrescarAcreditadoDe(?SaleInvoice $invoice): void
    {
        if (! $invoice) {
            return;
        }

        $acreditado = (float) CreditDebitNote::withoutGlobalScopes()
            ->where('sale_invoice_id', $invoice->id)
            ->where('type', CreditDebitNote::TYPE_CREDIT)
            ->where('status', CreditDebitNote::STATUS_POSTED)
            // Lo que se devolvio en efectivo ya salio del cajon: no rebaja
            // ademas el saldo, o la devolucion contaria dos veces.
            ->where(fn ($q) => $q->whereNull('refund_method')
                ->orWhere('refund_method', CreditDebitNote::REFUND_BALANCE))
            ->sum('total');

        $invoice->forceFill(['credited_amount' => round($acreditado, 2)])->save();
    }

    /**
     * Una nota no puede acreditar mas de lo que la factura todavia debe.
     *
     * Sin esto se podia devolver tres veces la misma venta: cada nota pasaba
     * sola porque nadie miraba las anteriores. Y ahora que la nota rebaja el
     * saldo, acreditar de mas dejaria la factura en negativo, que la cartera
     * leeria como un saldo a favor que nadie le dio al cliente.
     *
     * Solo aplica a notas credito atadas a una factura. Una nota suelta no
     * tiene contra que compararse.
     */
    protected function exigirQueQuepaEnLaFactura(CreditDebitNote $note): void
    {
        if (! $note->isCredit() || ! $note->sale_invoice_id) {
            return;
        }

        $invoice = $note->saleInvoice;

        if (! $invoice) {
            return;
        }

        $disponible = $invoice->creditableRemaining();
        $total = round((float) $note->total, 2);

        if ($total > $disponible + 0.01) {
            throw new RuntimeException(sprintf(
                'Esta nota acredita $%s pero a la factura %s solo le quedan $%s por acreditar. '
                .'Revisa las cantidades, o si ya hay otra nota sobre la misma factura.',
                number_format($total, 0, ',', '.'),
                $invoice->fullNumber(),
                number_format($disponible, 0, ',', '.'),
            ));
        }
    }

    /**
     * Le pone resolución DIAN a una nota que se contabilizó sin ella.
     *
     * Existe para rescatar las notas que quedaron numeradas a mano mientras el
     * motor las buscaba por la sede. Sin esto habría que anularlas y rehacerlas,
     * y una nota crédito anulada deja la cuenta del cliente donde no debe.
     *
     * También sirve para las notas que la DIAN **rechazó**, aunque ya tengan
     * resolución. El rechazo típico es «Documento ya emitido»: ese consecutivo
     * está ocupado allá —lo gastó otro sistema, o un envío anterior— y la nota
     * no va a pasar nunca con el número que tiene. Antes había que anularla y
     * rehacerla, y una nota crédito anulada deja la cuenta del cliente donde no
     * debe.
     *
     * Y sirve las veces que haga falta mientras la nota no se haya enviado. Al
     * renumerar, la nota queda pendiente de envío, y negarse entonces sería la
     * peor trampa posible: la primera pasada deja el número donde estaba —si el
     * contador no se había movido— y la segunda, la que lo arreglaría, ya no se
     * puede hacer.
     *
     * Renumerar un documento contabilizado no es gratis, así que solo procede
     * cuando la DIAN nunca lo autorizó: si lo autorizó, ese número ya existe
     * fuera de aquí y cambiarlo dejaría los dos sistemas hablando de documentos
     * distintos. Por eso se mira el CUFE además del estado: es la prueba de que
     * la DIAN alguna vez le dio validez, y sobrevive a cualquier estado que
     * alguien haya dejado mal guardado después.
     *
     * El asiento se arrastra con la nota, porque su referencia es justamente el
     * número que cambia.
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

        if ($note->cufe) {
            throw new RuntimeException(
                'Esta nota tiene CUFE: la DIAN la autorizó en algún momento con su '
                .'número actual. Renumerarla dejaría dos documentos distintos hablando '
                .'de la misma operación.'
            );
        }

        // Lo único que de verdad impide renumerar, además del CUFE: que el número
        // vaya viajando. Hasta que la DIAN no responda, nadie sabe con qué se
        // quedó allá, y cambiarlo aquí mientras tanto deja ese número en el aire.
        if ($note->dian_status === CreditDebitNote::DIAN_SENT) {
            throw new RuntimeException(
                'Esta nota está esperando la respuesta de la DIAN. Espera a que responda: '
                .'si la rechaza, podrás renumerarla y reintentar.'
            );
        }

        $docTypeId = $note->dianTypeDocumentId();

        return DB::transaction(function () use ($note, $docTypeId) {
            // Se excluye la propia nota del conteo: si no, se compara contra sí
            // misma y el «siguiente libre» sale uno más arriba del que ya tenía.
            // Una nota que era la 1 se volvía la 2 al rescatarla.
            $reserva = $this->documentNumberer->reserveGlobal(
                $note->company_id,
                $docTypeId,
                'credit_debit_notes',
                excluirId: $note->id,
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

    /**
     * Pone la nota en la fecha de hoy, para que se pueda transmitir.
     *
     * La DIAN exige que la fecha de generación del documento sea la misma en que
     * se firma —regla CAD09— y la firma la pone el proveedor en el momento del
     * envío. Una nota contabilizada hace once días se rechaza siempre, tenga el
     * consecutivo que tenga: no hay manera de enviarla con su fecha vieja. Lo
     * único que se puede hacer es emitirla hoy.
     *
     * El asiento se mueve con ella. Dejarlo en la fecha vieja pondría el libro a
     * hablar de un documento que, para la DIAN, se emitió otro día.
     *
     * @throws RuntimeException con un mensaje que se le puede mostrar al usuario
     */
    public function ponerFechaDeHoy(CreditDebitNote $note): CreditDebitNote
    {
        if (! $note->isPosted()) {
            throw new RuntimeException('La nota todavía no está contabilizada.');
        }

        if ($note->cufe || $note->dian_status === CreditDebitNote::DIAN_ACCEPTED) {
            throw new RuntimeException(
                'La DIAN ya autorizó esta nota: su fecha es parte del documento que '
                .'existe allá y no se puede mover.'
            );
        }

        if ($note->dian_status === CreditDebitNote::DIAN_SENT) {
            throw new RuntimeException(
                'Esta nota está esperando la respuesta de la DIAN. Espera a que responda '
                .'antes de moverle la fecha.'
            );
        }

        $hoy = now();

        if ($note->date?->isSameDay($hoy)) {
            throw new RuntimeException('La nota ya está fechada hoy.');
        }

        return DB::transaction(function () use ($note, $hoy) {
            $note->update([
                'date' => $hoy->toDateString(),
                // La hora también viaja en el documento y sale de posted_at.
                'posted_at' => $hoy,
                'dian_status' => CreditDebitNote::DIAN_PENDING,
                'dian_error_message' => null,
            ]);

            $asientos = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $note->company_id)
                ->where(fn ($q) => $q
                    ->whereKey($note->journal_entry_id)
                    ->orWhere('reference', $note->fullNumber()));

            $asientos->update(['date' => $hoy->toDateString()]);

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
        // Una devolución POS no se transmite: no gasta consecutivo de la
        // resolución de notas ni queda en la cola de envío. Quemarle un número
        // a la resolución sería peor que inútil —ese consecutivo quedaría con
        // un hueco que la DIAN pregunta después.
        if ($note->esPos()) {
            return;
        }

        // El mismo código DIAN con el que la nota se transmite. Tenerlo en un
        // solo sitio es lo que impide que la resolución quede registrada bajo un
        // tipo y el documento se envíe declarando otro.
        $docTypeId = $note->dianTypeDocumentId();

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
        $vatAccountId = DefaultAccounts::porCodigo('240805', (int) $note->company_id);

        $defaultIncomeAccountId = DefaultAccounts::porCodigo('4135', (int) $note->company_id);

        $receivableAccountId = $note->customer?->default_receivable_account_id
            ?? DefaultAccounts::porCodigo('1305', (int) $note->company_id);

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

            // CR la contrapartida: la CxC si se le rebaja lo que debe, o la
            // caja si se le devolvió la plata. Una venta de contado ya se
            // cobró: ahí no hay cartera que bajar, hay efectivo que sale.
            $devuelvePlata = $note->devuelvePlata();

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'line_number' => $line,
                'account_id' => $devuelvePlata
                    ? PaymentAccountResolver::forMethod($note->refund_method)
                    : $receivableAccountId,
                'third_party_id' => $note->third_party_id,
                'description' => $devuelvePlata
                    ? "Devolución de dinero a {$note->customer->name}"
                    : "Reversa CxC {$note->customer->name}",
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
        $defaultCogsAccountId = DefaultAccounts::porCodigo('6135', (int) $note->company_id);

        $defaultInventoryAccountId = DefaultAccounts::porCodigo('1435', (int) $note->company_id);

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
