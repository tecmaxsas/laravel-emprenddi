<?php

namespace App\Services\Sales;

use App\Models\CashRegisterSession;
use App\Models\CreditDebitNote;
use App\Models\CustomerAdvance;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PromotionUsage;
use App\Models\SaleInvoice;
use App\Services\Accounting\JournalEntryNumberer;
use App\Support\ClockFormat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Borrar una factura POS, devolviendo todo lo que movió.
 *
 * **Borrar no es destruir.** La factura se marca como eliminada y desaparece de
 * las listas, pero la fila se queda. Es deliberado por dos razones: una
 * resolución POS también tiene un rango autorizado, y un consecutivo que
 * desaparece sin rastro es un hueco que nadie sabrá explicarle a la DIAN; y la
 * bitácora de auditoría necesita poder decir quién borró qué.
 *
 * **Solo facturas POS.** Una factura electrónica aceptada por la DIAN no se
 * borra: existe fuera de este sistema. Para deshacerla está la nota crédito.
 *
 * Lo que se devuelve, en este orden —importa, porque cada paso deja el terreno
 * listo para el siguiente—:
 *
 *   1. Los pagos, con sus asientos. Sin esto, `cancel()` se niega a seguir.
 *   2. Los anticipos que se hubieran aplicado, que vuelven a quedar disponibles.
 *   3. Los bonos: lo redimido se devuelve al saldo, y el emitido en esta venta
 *      se anula.
 *   4. Los usos de promoción, para que un cupón de «una vez por cliente» se
 *      pueda volver a usar.
 *   5. Inventario, seriales, asientos de venta y de costo, y comisiones — todo
 *      eso ya lo sabe hacer `cancel()` y no tiene sentido reescribirlo.
 *   6. Y al final, la factura se marca eliminada.
 */
class SaleInvoiceDeleter
{
    public function __construct(
        private readonly SaleInvoiceEngine $engine,
        private readonly JournalEntryNumberer $numberer,
    ) {}

    /**
     * Por qué no se puede borrar esta factura, o null si sí se puede.
     *
     * Separado de `delete()` para que la pantalla pueda explicar el motivo
     * antes de que el usuario haga clic, en vez de después.
     */
    public function motivoParaNoBorrar(SaleInvoice $invoice): ?string
    {
        if (! $invoice->isPosInvoice()) {
            return 'Solo se pueden borrar facturas POS. Una factura electrónica ya existe '
                .'ante la DIAN: para deshacerla emite una nota crédito.';
        }

        if (in_array($invoice->dian_status, [SaleInvoice::DIAN_SENT, SaleInvoice::DIAN_ACCEPTED], true)) {
            return 'Esta factura se envió a la DIAN. No se puede borrar: emite una nota crédito.';
        }

        if ($invoice->trashed()) {
            return 'Esta factura ya está borrada.';
        }

        $tieneNotas = CreditDebitNote::withoutGlobalScopes()
            ->where('sale_invoice_id', $invoice->id)
            ->exists();

        if ($tieneNotas) {
            return 'Esta factura tiene notas crédito o débito asociadas. '
                .'Anula primero las notas.';
        }

        // Un turno cerrado ya fue contado y firmado. Quitarle una venta deja el
        // cuadre mentiroso para siempre, y nadie lo va a notar hasta la
        // auditoría. Si el turno sigue abierto, los totales se recalculan solos.
        $turno = $invoice->cashRegisterSession;

        if ($turno && $turno->status !== CashRegisterSession::STATUS_OPEN) {
            return sprintf(
                'La venta pertenece a un turno de caja ya cerrado (%s). Borrarla dejaría el '
                .'cuadre de ese turno descuadrado. Registra una devolución o una nota crédito.',
                $turno->closed_at?->format(ClockFormat::DATETIME) ?? 'cerrado',
            );
        }

        return null;
    }

    /**
     * Borra la factura y devuelve todo lo que movió.
     *
     * @throws RuntimeException si no se puede borrar
     */
    public function delete(SaleInvoice $invoice, ?string $motivo = null): void
    {
        if ($razon = $this->motivoParaNoBorrar($invoice)) {
            throw new RuntimeException($razon);
        }

        $invoice->load(['lines.product', 'customer', 'location', 'journalEntry.lines', 'payments']);

        DB::transaction(function () use ($invoice, $motivo) {
            $this->devolverPagos($invoice);
            $this->devolverBonos($invoice);
            $this->borrarUsosDePromocion($invoice);

            // Inventario, seriales, asiento de venta, asiento de costo y
            // comisiones. Ya está escrito y probado: reescribirlo aquí sería
            // tener dos versiones de la misma reversa, y algún día una de las
            // dos se quedaría atrás.
            if ($invoice->status === SaleInvoice::STATUS_POSTED) {
                $this->engine->cancel($invoice->fresh(['lines.product', 'customer', 'location', 'journalEntry.lines']));
            }

            $invoice->refresh();

            $invoice->update([
                'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '')
                    .'Borrada el '.now()->format(ClockFormat::DATETIME)
                    .' por '.(Auth::user()?->name ?? 'el sistema')
                    .($motivo ? ' — '.$motivo : '')),
            ]);

            $invoice->delete();
        });
    }

    /**
     * Devuelve los pagos y reversa sus asientos.
     *
     * Va primero porque `cancel()` se niega a anular una factura con pagos, y
     * una venta POS casi siempre está pagada: sin este paso la función no
     * serviría para nada.
     */
    private function devolverPagos(SaleInvoice $invoice): void
    {
        foreach ($invoice->payments as $pago) {
            if ($pago->journal_entry_id) {
                $asiento = JournalEntry::withoutGlobalScopes()
                    ->with('lines')
                    ->find($pago->journal_entry_id);

                if ($asiento) {
                    $this->reversar($invoice, $asiento,
                        "Reversa del pago de la venta {$invoice->fullNumber()}");
                }
            }

            // Un pago hecho con un anticipo vuelve a dejarlo disponible: esa
            // plata sigue siendo del cliente.
            if ($pago->customer_advance_id) {
                $anticipo = CustomerAdvance::withoutGlobalScopes()->find($pago->customer_advance_id);

                if ($anticipo) {
                    // Lo disponible de un anticipo es `amount - applied_amount`,
                    // asi que devolverlo es bajar lo aplicado, no subir un saldo.
                    $anticipo->decrement('applied_amount', (float) $pago->amount);
                }
            }

            $pago->delete();
        }

        $invoice->update(['paid_amount' => 0]);
    }

    /**
     * Los bonos vuelven a su estado anterior.
     *
     * Dos casos opuestos: lo que la venta REDIMIÓ se devuelve al saldo del
     * bono, y lo que la venta EMITIÓ deja de existir —ese bono se vendió en una
     * factura que ya no está—.
     */
    private function devolverBonos(SaleInvoice $invoice): void
    {
        $redenciones = GiftCardTransaction::withoutGlobalScopes()
            ->where('sale_invoice_id', $invoice->id)
            ->where('type', GiftCardTransaction::TYPE_REDEEM)
            ->get();

        foreach ($redenciones as $redencion) {
            $bono = GiftCard::withoutGlobalScopes()->find($redencion->gift_card_id);

            $bono?->refund(
                abs((float) $redencion->amount),
                Auth::id() ?? 0,
                $invoice->id,
                "Devolución por borrado de la venta {$invoice->fullNumber()}",
            );
        }

        $emitidos = GiftCard::withoutGlobalScopes()
            ->where('issued_via_sale_invoice_id', $invoice->id)
            ->get();

        foreach ($emitidos as $bono) {
            if ($bono->status !== GiftCard::STATUS_CANCELLED) {
                $bono->cancel(Auth::id() ?? 0, "Borrado de la venta {$invoice->fullNumber()}");
            }
        }
    }

    /**
     * Borra los usos de promoción de esta venta.
     *
     * Se borran y no se marcan: una promoción de «una vez por cliente» cuenta
     * filas, y una fila marcada seguiría contando. El cliente tiene que quedar
     * como si la venta nunca hubiera ocurrido.
     */
    private function borrarUsosDePromocion(SaleInvoice $invoice): void
    {
        PromotionUsage::withoutGlobalScopes()
            ->where('sale_invoice_id', $invoice->id)
            ->delete();
    }

    /**
     * Mismo criterio que la reversa del motor: un asiento espejo.
     *
     * El tipo es `reversal` y no algo más descriptivo como `payment_reversal`
     * porque la tabla tiene un CHECK con la lista cerrada de tipos; inventarse
     * uno aquí hace fallar el borrado entero al momento de insertar.
     */
    private function reversar(SaleInvoice $invoice, JournalEntry $original, string $descripcion): void
    {
        $numero = $this->numberer->next($invoice->company, 'AS');

        $reversa = JournalEntry::create([
            'company_id' => $invoice->company_id,
            'prefix' => 'AS',
            'number' => $numero,
            'date' => now()->toDateString(),
            'type' => 'reversal',
            'reference' => 'BORR-'.$invoice->fullNumber(),
            'third_party_id' => $invoice->third_party_id,
            'description' => $descripcion,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $original->total_credit,
            'total_credit' => $original->total_debit,
        ]);

        $linea = 1;

        foreach ($original->lines as $original_linea) {
            JournalEntryLine::create([
                'journal_entry_id' => $reversa->id,
                'line_number' => $linea++,
                'account_id' => $original_linea->account_id,
                'third_party_id' => $original_linea->third_party_id,
                'description' => "Reversa: {$original_linea->description}",
                'debit' => $original_linea->credit,
                'credit' => $original_linea->debit,
            ]);
        }
    }
}
