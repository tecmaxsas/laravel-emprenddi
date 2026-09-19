<?php

namespace App\Services\Sales;

use App\Models\CashRegisterSession;
use App\Models\CustomerAdvance;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Services\Accounting\JournalEntryNumberer;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Support\ClockFormat;
use App\Support\ModuleGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Borrar un cobro registrado por error.
 *
 * Hasta ahora un pago mal digitado —el monto equivocado, el método
 * equivocado, o sencillamente dos veces el mismo— solo se podía deshacer
 * borrando la factura entera. Eso obliga a rehacer la venta y, si la factura
 * ya fue a la DIAN, ni siquiera es posible.
 *
 * **Borrar no es destruir.** El pago se marca como eliminado y desaparece de
 * las listas, pero la fila se queda: la bitácora de auditoría tiene que poder
 * decir quién quitó qué cobro y cuándo.
 *
 * Y sobre todo: **el asiento contable no se borra, se reversa**. Un asiento
 * que desaparece deja el libro con un hueco que no cuadra contra nada. La
 * reversa es un asiento nuevo, con la misma fecha de hoy y los débitos y
 * créditos cambiados de lado, que es como se corrige en contabilidad.
 *
 * Lo que se deshace, en orden:
 *
 *   1. El asiento del cobro, con una reversa.
 *   2. El anticipo del que salió, si vino de uno: esa plata vuelve a quedar
 *      disponible para el cliente.
 *   3. El saldo y el estado de pago de la factura.
 *   4. Y el pago se marca eliminado.
 *
 * La caja no necesita paso propio: el resumen del turno suma los pagos vivos,
 * así que al marcar este como eliminado desaparece solo del arqueo. Por eso
 * mismo un turno ya cerrado es motivo para no dejar borrar.
 */
class PaymentDeleter
{
    public function __construct(
        private readonly JournalEntryNumberer $numberer,
    ) {}

    /**
     * Por qué no se puede borrar este cobro, o null si sí se puede.
     *
     * Separado de `delete()` para que la pantalla pueda explicar el motivo
     * antes de que el usuario haga clic, y no después.
     */
    public function motivoParaNoBorrar(Payment $pago): ?string
    {
        if ($pago->trashed()) {
            return 'Este pago ya está borrado.';
        }

        // Un turno cerrado ya fue contado y firmado. Quitarle un cobro deja el
        // cuadre mentiroso para siempre, y nadie lo va a notar hasta la
        // auditoría. Si el turno sigue abierto, los totales se recalculan solos.
        $turno = $pago->cashSession;

        if ($turno && $turno->status !== CashRegisterSession::STATUS_OPEN) {
            return sprintf(
                'Este cobro pertenece a un turno de caja ya cerrado (%s). Borrarlo dejaría '
                .'descuadrado el arqueo de ese turno. Registra una nota crédito o un ajuste.',
                $turno->closed_at?->format(ClockFormat::datetime()) ?? 'cerrado',
            );
        }

        return null;
    }

    /**
     * Qué va a pasar si se borra, en palabras.
     *
     * La pantalla lo muestra en la confirmación. Un cobro no se borra solo:
     * arrastra un asiento contable y a veces el saldo a favor de un cliente, y
     * quien confirma tiene derecho a saberlo antes.
     *
     * @return list<string>
     */
    public function consecuencias(Payment $pago): array
    {
        $consecuencias = [];

        $documento = $this->documento($pago);

        if ($documento) {
            $consecuencias[] = 'El saldo pendiente de '.$documento->fullNumber()
                .' sube $'.number_format((float) $pago->amount, 0, ',', '.').'.';
        }

        if ($pago->customer_advance_id) {
            $consecuencias[] = 'Los $'.number_format((float) $pago->amount, 0, ',', '.')
                .' vuelven a quedar como saldo a favor del cliente.';
        }

        if ($pago->journal_entry_id && ModuleGate::active(ModuleGate::ACCOUNTING)) {
            $consecuencias[] = 'Se genera un asiento de reversa. El asiento original '
                .'no se borra: en contabilidad los errores se corrigen reversando.';
        }

        if ($pago->cash_register_session_id) {
            $consecuencias[] = 'El turno de caja deja de contar este cobro, '
                .'así que el esperado en caja baja.';
        }

        return $consecuencias;
    }

    /**
     * Borra el cobro y deshace lo que movió.
     *
     * @throws RuntimeException si no se puede borrar
     */
    public function delete(Payment $pago, ?string $motivo = null): void
    {
        if ($razon = $this->motivoParaNoBorrar($pago)) {
            throw new RuntimeException($razon);
        }

        DB::transaction(function () use ($pago, $motivo) {
            $this->reversarAsiento($pago);
            $this->devolverAnticipo($pago);

            $pago->update([
                'description' => trim(($pago->description ? $pago->description."\n" : '')
                    .'Borrado el '.now()->format(ClockFormat::datetime())
                    .' por '.(Auth::user()?->name ?? 'el sistema')
                    .($motivo ? ' — '.$motivo : '')),
            ]);

            $pago->delete();

            // Después de borrarlo, para que la suma no lo cuente.
            $this->recalcularDocumento($pago);
        });
    }

    // ------------------------------------------------------------ pasos

    /**
     * El asiento del cobro se reversa, no se borra.
     *
     * Un asiento que desaparece deja el libro con un hueco: el libro mayor de
     * la cuenta de caja tendría un movimiento menos y el saldo no cuadraría
     * contra nada, sin ninguna traza de por qué.
     */
    private function reversarAsiento(Payment $pago): void
    {
        if (! $pago->journal_entry_id) {
            return;
        }

        $original = JournalEntry::withoutGlobalScopes()
            ->with('lines')
            ->find($pago->journal_entry_id);

        if (! $original) {
            return;
        }

        $documento = $this->documento($pago);
        $referencia = $documento?->fullNumber() ?? ('PAGO-'.$pago->id);

        $reversa = JournalEntry::create([
            'company_id' => $pago->company_id,
            'prefix' => 'AS',
            'number' => $this->numberer->next($pago->company, 'AS'),
            'date' => now()->toDateString(),
            'type' => 'reversal',
            'reference' => 'ANUL-'.$referencia,
            'third_party_id' => $pago->third_party_id,
            'description' => "Reversa del cobro de {$referencia}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
            'total_debit' => $original->total_credit,
            'total_credit' => $original->total_debit,
        ]);

        $linea = 1;

        foreach ($original->lines as $lineaOriginal) {
            JournalEntryLine::create([
                'journal_entry_id' => $reversa->id,
                'line_number' => $linea++,
                'account_id' => $lineaOriginal->account_id,
                'third_party_id' => $lineaOriginal->third_party_id,
                'description' => "Reversa: {$lineaOriginal->description}",
                'debit' => $lineaOriginal->credit,
                'credit' => $lineaOriginal->debit,
            ]);
        }
    }

    /**
     * Un cobro hecho con un anticipo devuelve ese saldo a favor.
     *
     * Lo disponible de un anticipo es `amount - applied_amount`, así que
     * devolverlo es bajar lo aplicado, no subir un saldo.
     */
    private function devolverAnticipo(Payment $pago): void
    {
        if (! $pago->customer_advance_id) {
            return;
        }

        $anticipo = CustomerAdvance::withoutGlobalScopes()->find($pago->customer_advance_id);

        if (! $anticipo) {
            return;
        }

        // Nunca por debajo de cero: si el dato ya venía torcido, dejarlo en
        // negativo haría que el cliente apareciera con más saldo del que tiene.
        $anticipo->update([
            'applied_amount' => max(0, (float) $anticipo->applied_amount - (float) $pago->amount),
        ]);
    }

    /**
     * El documento vuelve a quedar con su saldo pendiente.
     *
     * Se delega en el motor de cada tipo: ahí viven las reglas de cuándo una
     * factura está parcial, pagada o vencida, y las comisiones que dependen de
     * eso. Escribirlas aquí otra vez sería tener dos versiones de la misma
     * regla, y algún día una se quedaría atrás.
     */
    private function recalcularDocumento(Payment $pago): void
    {
        $documento = $this->documento($pago);

        if ($documento instanceof SaleInvoice) {
            app(SaleInvoiceEngine::class)->recomputePaymentStatus($documento);

            return;
        }

        if ($documento instanceof PurchaseInvoice) {
            app(PurchaseInvoiceEngine::class)->recomputePaymentStatus($documento);
        }
    }

    /** La factura a la que se le aplicó el cobro. */
    private function documento(Payment $pago): SaleInvoice|PurchaseInvoice|null
    {
        if ($pago->paymentable_type === SaleInvoice::class) {
            return SaleInvoice::withoutGlobalScopes()->find($pago->paymentable_id);
        }

        if ($pago->paymentable_type === PurchaseInvoice::class) {
            return PurchaseInvoice::withoutGlobalScopes()->find($pago->paymentable_id);
        }

        return null;
    }
}
