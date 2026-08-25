<?php

namespace App\Jobs;

use App\Models\SaleInvoice;
use App\Services\Dian\DianInvoiceSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Transmite una factura electronica a DIAN en segundo plano.
 *
 * Los POS (retail, restaurante, parqueadero) despachan este job despues de
 * contabilizar y cobrar: el cajero no espera los ~4 segundos que tarda el
 * roundtrip a apidian, y una caida de red no traba la venta.
 *
 * Reintentos con backoff creciente porque las fallas tipicas (timeout de
 * apidian, DIAN saturada) se resuelven solas en minutos. Un rechazo de DIAN
 * NO se reintenta: el sender ya lo persistio como 'rejected' y reintentarlo
 * daria siempre el mismo resultado hasta que alguien corrija los datos.
 */
class SendInvoiceToDian implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Intentos totales antes de darse por vencido. */
    public int $tries = 4;

    /** Espera entre reintentos: 30s, 2min, 5min. */
    public array $backoff = [30, 120, 300];

    /** Un envio nunca deberia pasar de aqui; corta cuelgues del API. */
    public int $timeout = 120;

    public function __construct(
        public int $invoiceId,
    ) {}

    /**
     * Evita transmitir dos veces la misma factura si el job se encola repetido
     * (doble clic, reintento del worker tras un deploy). El lock se suelta al
     * terminar el job o, como maximo, a los 10 minutos.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    /**
     * Punto unico de entrada para los POS: encola el envio solo si la factura
     * de verdad va a DIAN. Devuelve true si quedo encolada, para que quien
     * llama pueda avisarle al cajero.
     *
     * Se despacha afterCommit porque los POS crean, contabilizan y cobran
     * dentro de una transaccion: sin eso el worker podria leer la factura
     * antes de que exista para el resto de conexiones.
     */
    public static function dispatchFor(?SaleInvoice $invoice): bool
    {
        if (! $invoice || $invoice->isPosInvoice() || $invoice->isDianAccepted() || ! $invoice->isPosted()) {
            return false;
        }

        self::dispatch($invoice->id)->afterCommit();

        return true;
    }

    public function handle(DianInvoiceSender $sender): void
    {
        $invoice = SaleInvoice::withoutGlobalScopes()->find($this->invoiceId);

        if (! $invoice) {
            return;
        }

        // Condiciones que hacen el envio innecesario o invalido. Se revisan
        // aqui y no solo en quien despacha porque entre el encolado y la
        // ejecucion la factura pudo cambiar (anulada, ya aceptada, reenviada
        // a mano desde la vista de factura).
        if ($invoice->isPosInvoice() || $invoice->isDianAccepted() || ! $invoice->isPosted()) {
            return;
        }

        try {
            $result = $sender->send($invoice);
        } catch (\Throwable $e) {
            Log::warning('SendInvoiceToDian: falló el envío', [
                'invoice_id' => $invoice->id,
                'invoice' => $invoice->fullNumber(),
                'company_id' => $invoice->company_id,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            // Precondiciones no configurables desde aqui (empresa sin token,
            // factura no contabilizada): reintentar no arregla nada.
            $this->fail($e);

            return;
        }

        if (! $result['ok']) {
            // DIAN respondio y rechazo: el sender ya dejo dian_status =
            // rejected con el motivo. No se reintenta.
            Log::info('SendInvoiceToDian: DIAN rechazó la factura', [
                'invoice_id' => $invoice->id,
                'invoice' => $invoice->fullNumber(),
                'status_code' => $result['status_code'],
                'message' => $result['message'],
            ]);
        }
    }
}
