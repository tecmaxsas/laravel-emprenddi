<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\SaleInvoice;
use RuntimeException;

/**
 * Reenvia el PDF + XML de una factura electronica ya emitida a un correo
 * alternativo, via /send-email de apidian.
 *
 * Casos tipicos: el cliente dio mal el correo, no le llego, o pide copia a
 * contabilidad. Solo aplica a documentos que existen en apidian — es decir,
 * facturas electronicas aceptadas o al menos transmitidas; las POS nunca
 * llegaron alla.
 */
class DianEmailResender
{
    /**
     * @param  array{alternate_email:string, cc?:array<int,string>, cc_as_cc?:bool}  $payload
     * @return array{ok:bool, message:string, attempts:?int, outgoing_mail:?string}
     */
    public function resend(SaleInvoice $invoice, array $payload): array
    {
        if ($invoice->isPosInvoice()) {
            throw new RuntimeException('Las facturas POS no se transmiten a DIAN, no hay documento electrónico que reenviar.');
        }

        if (! $invoice->cufe) {
            throw new RuntimeException('La factura no tiene CUFE: aún no existe en DIAN. Envíala primero.');
        }

        $email = trim((string) ($payload['alternate_email'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Indica el correo de destino.');
        }

        $config = CompanyConfig::query()->where('company_id', $invoice->company_id)->first();
        if (! $config || ! $config->api_token) {
            throw new RuntimeException('La empresa no tiene configurada la facturación electrónica DIAN.');
        }

        $body = [
            'prefix' => (string) $invoice->prefix,
            'number' => (string) $invoice->number,
            'alternate_email' => $email,
        ];

        $cc = array_values(array_filter(array_map('trim', $payload['cc'] ?? [])));
        if ($cc !== []) {
            $body['email_cc_list'] = array_map(fn (string $mail) => ['email' => $mail], $cc);
            $body['send_email_cc_list_as_email_cc'] = (bool) ($payload['cc_as_cc'] ?? true);
        }

        $result = (new DianApiClient($config))->sendDocumentEmail($body);
        $data = $result['data'] ?? [];

        // El API responde 200 con success:false cuando no encuentra el
        // documento por prefijo/numero, asi que no basta con mirar el HTTP.
        $ok = ($data['success'] ?? false) === true;

        return [
            'ok' => $ok,
            'message' => (string) ($data['message'] ?? $result['error'] ?? ($ok ? 'Envío realizado con éxito' : 'No fue posible reenviar el correo.')),
            'attempts' => isset($data['attemps']) ? (int) $data['attemps'] : null,
            'outgoing_mail' => $data['outgoing_mail'] ?? null,
        ];
    }
}
