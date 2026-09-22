<?php

namespace App\Services\Dian;

use App\Models\CreditDebitNote;
use App\Models\Dian\CompanyConfig;
use RuntimeException;

/**
 * Orquesta el envío de NC/ND a apidian. Espejo de DianInvoiceSender pero
 * llama el endpoint correcto según type (credit-note / debit-note).
 */
class CreditDebitNoteSender
{
    public function __construct(
        protected CreditDebitNoteUblBuilder $builder,
    ) {}

    public function send(CreditDebitNote $note): array
    {
        if (! $note->isPosted()) {
            throw new RuntimeException('Solo se pueden enviar NC/ND contabilizadas.');
        }

        $config = CompanyConfig::query()->where('company_id', $note->company_id)->first();

        if (! $config || ! $config->company_registered) {
            throw new RuntimeException('La empresa no ha completado el registro DIAN.');
        }

        if (! $config->api_token) {
            throw new RuntimeException('Falta el token DIAN.');
        }

        // La DIAN exige que la fecha del documento sea la misma en que se firma
        // (regla CAD09), y la firma la pone el proveedor ahora. Enviar una nota
        // con fecha vieja no falla aquí: falla allá, y vuelve como un rechazo
        // que cuesta leer. Mejor decirlo antes, con la salida a la vista.
        if (! $note->date?->isSameDay(now())) {
            throw new RuntimeException(sprintf(
                'Esta nota está fechada el %s y la DIAN exige que la fecha del documento '
                .'sea la misma en que se firma, que es hoy. Usa «Poner fecha de hoy» en la '
                .'nota y vuelve a enviarla.',
                $note->date?->format('Y-m-d') ?? 'sin fecha',
            ));
        }

        $payload = $this->builder->build($note);

        $note->update([
            'dian_status' => CreditDebitNote::DIAN_SENT,
            'dian_sent_at' => now(),
        ]);

        $client = new DianApiClient($config);
        $result = $note->isCredit()
            ? $client->sendCreditNote($payload)
            : $client->sendDebitNote($payload);

        return $this->processResponse($note, $result);
    }

    protected function processResponse(CreditDebitNote $note, array $result): array
    {
        $data = $result['data'] ?? [];

        if (! $result['ok'] && empty($data)) {
            $note->update([
                'dian_status' => CreditDebitNote::DIAN_REJECTED,
                'dian_error_message' => $result['error'] ?? 'Error de conexión con apidian',
                'dian_response' => $result,
            ]);
            return ['ok' => false, 'message' => $result['error'] ?? 'Error de conexión', 'cufe' => null, 'status_code' => null];
        }

        if (isset($data['errors'])) {
            $msgs = $this->flattenErrors($data['errors']);
            $note->update([
                'dian_status' => CreditDebitNote::DIAN_REJECTED,
                'dian_error_message' => $msgs,
                'dian_response' => $data,
            ]);
            return ['ok' => false, 'message' => 'Errores: '.$msgs, 'cufe' => null, 'status_code' => null];
        }

        if (isset($data['exception'])) {
            $note->update([
                'dian_status' => CreditDebitNote::DIAN_REJECTED,
                'dian_error_message' => 'Excepción: '.DianErrorReader::resumen((array) $data['exception']),
                'dian_response' => $data,
            ]);
            return ['ok' => false, 'message' => 'Excepción del API', 'cufe' => null, 'status_code' => null];
        }

        $dianResponse = $data['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult'] ?? null;

        // En notas el identificador se llama CUDE, no CUFE: es el mismo número
        // con otro nombre. Leer solo `cufe` dejaba vacío el código de una nota
        // que la DIAN había aceptado, y como la aceptación lo exigía, la nota
        // quedaba marcada como rechazada con un «Procesado Correctamente» en el
        // mensaje. El cliente la daba por fallida y la reenviaba, y el segundo
        // envío sí fallaba de verdad: documento ya emitido.
        $cufe = DianErrorReader::texto($data['cude'] ?? $data['cufe'] ?? null) ?: null;

        if (! $dianResponse) {
            $note->update([
                'dian_status' => CreditDebitNote::DIAN_REJECTED,
                'dian_error_message' => 'Sin respuesta de DIAN.',
                'dian_response' => $data,
            ]);
            return ['ok' => false, 'message' => 'Sin respuesta DIAN', 'cufe' => $cufe, 'status_code' => null];
        }

        $statusCode = DianErrorReader::texto($dianResponse['StatusCode'] ?? null);
        $isValid = filter_var($dianResponse['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Quien decide si pasó es la DIAN: IsValid, o el código 00 —«Procesado
        // Correctamente»—. Que el proveedor no devuelva el código no convierte
        // en rechazo un documento aceptado; solo nos deja sin CUDE, y eso se
        // dice aparte.
        if ($isValid || $statusCode === '00') {
            // Las notificaciones no son rechazos: la DIAN acepta el documento y
            // avisa de algo a corregir para la próxima. Se guardan porque son
            // útiles —«no se informó el número de la factura referenciada» es un
            // enlace que falta— pero la pantalla las muestra como aviso, no como
            // error.
            $avisos = DianErrorReader::reglas($dianResponse);

            $note->update([
                'dian_status' => CreditDebitNote::DIAN_ACCEPTED,
                'dian_status_code' => $statusCode,
                'cufe' => $cufe,
                'qr_url' => $cufe
                    ? 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufe
                    : null,
                'dian_error_message' => $cufe
                    ? ($avisos === [] ? null : implode(' · ', $avisos))
                    : 'La DIAN la aceptó, pero el proveedor no devolvió el CUDE, así que no hay '
                        .'PDF ni QR. Reclámalo al proveedor: el documento ya está radicado y '
                        .'volver a enviarlo lo duplicaría.',
                'dian_response' => $data,
            ]);

            return ['ok' => true, 'message' => 'Aceptada por DIAN', 'cufe' => $cufe, 'status_code' => $statusCode];
        }

        $errorMsg = $this->extractDianError($dianResponse, $statusCode);
        $note->update([
            'dian_status' => CreditDebitNote::DIAN_REJECTED,
            'dian_status_code' => $statusCode,
            'cufe' => $cufe,
            'dian_error_message' => $errorMsg,
            'dian_response' => $data,
        ]);

        return ['ok' => false, 'message' => $errorMsg, 'cufe' => $cufe, 'status_code' => $statusCode];
    }

    /**
     * Antes recorría un solo nivel y hacía `(string) $msg`. Cuando el proveedor
     * anidaba un nivel más, ese elemento era un array y todo reventaba con
     * «Array to string conversion»: el usuario perdía el mensaje real, que era
     * justo lo que necesitaba para corregir el envío.
     */
    protected function flattenErrors(array $errors): string
    {
        return DianErrorReader::resumen($errors);
    }

    /**
     * El motivo del rechazo, con lo que dijo la DIAN por delante.
     *
     * El código 99 se traducía a «Documento ya emitido» y ahí terminaba la
     * lectura. No es lo que significa: la DIAN lo usa para decir que el
     * documento trae errores en campos obligatorios, y esos errores vienen en
     * la misma respuesta, en la lista que este método antes ni miraba. El
     * resultado era un cliente renumerando una nota que la DIAN no tenía
     * registrada, porque el mensaje lo mandaba a arreglar el consecutivo
     * mientras el motivo real —el que sí se podía corregir— quedaba guardado en
     * la base y no lo veía nadie.
     *
     * Por eso las reglas van primero y el rótulo del código solo las encabeza.
     */
    protected function extractDianError(array $dianResponse, string $statusCode): string
    {
        // DianErrorReader sabe leer las tres formas en que llega esto: string
        // suelto, lista bajo `string`, y el bloque anidado que hacía reventar el
        // implode con «Array to string conversion».
        $reglas = DianErrorReader::reglas($dianResponse);

        $rotulos = [
            '115' => 'Consecutivo ya registrado en DIAN',
            '117' => 'Fecha mayor a la del sistema',
            '90' => 'Documento ya emitido',
            '99' => 'La DIAN encontró errores en el documento',
        ];

        $rotulo = $rotulos[$statusCode]
            ?? trim(DianErrorReader::texto($dianResponse['StatusDescription'] ?? null));

        if ($reglas !== []) {
            return ($rotulo !== '' ? $rotulo.': ' : '').implode(' · ', $reglas);
        }

        return ($rotulo !== '' ? $rotulo : 'Rechazada sin detalle').' (código '.$statusCode.')';
    }
}
