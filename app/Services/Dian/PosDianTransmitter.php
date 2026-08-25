<?php

namespace App\Services\Dian;

use App\Jobs\SendInvoiceToDian;
use App\Models\SaleInvoice;
use Illuminate\Support\Facades\Log;

/**
 * Transmite a DIAN una factura recien emitida desde un POS, esperando la
 * respuesta.
 *
 * El cajero espera los ~4 segundos del roundtrip a proposito: solo asi el
 * tiquete puede salir impreso con el CUFE y el QR oficiales, que es lo que
 * el cliente necesita recibir.
 *
 * Si la DIAN no llega a responder (red caida, apidian fuera), NO se traba la
 * venta: ya esta contabilizada y cobrada. Se encola el reintento y el tiquete
 * sale sin CUFE, avisandole al cajero. Un rechazo de DIAN si es respuesta
 * definitiva, asi que no se reintenta — se corrige y se reenvia a mano.
 *
 * IMPORTANTE: llamar SIEMPRE fuera de la transaccion que crea la factura.
 * Es una llamada HTTP lenta y dentro de la transaccion mantendria los locks
 * abiertos todo ese tiempo.
 */
class PosDianTransmitter
{
    public function __construct(
        protected DianInvoiceSender $sender,
    ) {}

    /**
     * @return array{sent:bool, accepted:bool, queued:bool, message:?string}
     */
    public function transmit(?SaleInvoice $invoice): array
    {
        $idle = ['sent' => false, 'accepted' => false, 'queued' => false, 'message' => null];

        if (! $invoice || $invoice->isPosInvoice() || $invoice->isDianAccepted() || ! $invoice->isPosted()) {
            return $idle;
        }

        try {
            $result = $this->sender->send($invoice);
        } catch (\Throwable $e) {
            // Precondiciones (empresa sin token, factura no contabilizada) o
            // cualquier fallo antes de siquiera armar la peticion.
            Log::warning('PosDianTransmitter: no se pudo transmitir', [
                'invoice_id' => $invoice->id,
                'invoice' => $invoice->fullNumber(),
                'message' => $e->getMessage(),
            ]);

            return ['sent' => true, 'accepted' => false, 'queued' => false, 'message' => $e->getMessage()];
        }

        if ($result['ok']) {
            return ['sent' => true, 'accepted' => true, 'queued' => false, 'message' => null];
        }

        // DIAN no respondio: vale la pena reintentar en segundo plano.
        $queued = false;
        if (! ($result['reached_dian'] ?? true)) {
            SendInvoiceToDian::dispatch($invoice->id)->afterCommit();
            $queued = true;
        }

        return [
            'sent' => true,
            'accepted' => false,
            'queued' => $queued,
            'message' => $result['message'],
        ];
    }

    /**
     * Texto para la notificacion del cajero. Null cuando no hay nada que
     * decirle (factura POS, o electronica aceptada sin novedad).
     */
    public function cashierNotice(array $result): ?string
    {
        if (! $result['sent'] || $result['accepted']) {
            return null;
        }

        return $result['queued']
            ? 'No se pudo contactar a la DIAN; el tiquete sale sin CUFE y el envío se reintenta solo.'
            : 'La DIAN no aceptó la factura: '.$result['message'].' El tiquete sale sin CUFE.';
    }
}
