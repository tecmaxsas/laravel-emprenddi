<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\SaleInvoice;
use RuntimeException;

/**
 * Consulta contra apidian el estado real de una factura ya emitida, usando su
 * CUFE, y sincroniza dian_status con lo que responda DIAN.
 *
 * Sirve para dos casos que en la practica se dan seguido:
 *  - La factura quedo en 'sent' porque se cayo la conexion justo despues de
 *    enviarla: DIAN puede haberla aceptado y nosotros no nos enteramos.
 *  - Conciliacion de cierre de mes: verificar contra la fuente de verdad en
 *    vez de confiar en lo que quedo guardado.
 *
 * La respuesta de este endpoint viene en GetStatusZipResponse, no en
 * SendBillSyncResponse como la de emision.
 */
class DianStatusChecker
{
    public function check(SaleInvoice $invoice): array
    {
        if ($invoice->isPosInvoice()) {
            throw new RuntimeException('Las facturas POS no se transmiten a DIAN, no tienen estado que consultar.');
        }

        if (! $invoice->cufe) {
            throw new RuntimeException('La factura no tiene CUFE. Envíala a DIAN antes de consultar su estado.');
        }

        $config = CompanyConfig::query()->where('company_id', $invoice->company_id)->first();

        if (! $config || ! $config->api_token) {
            throw new RuntimeException('La empresa no tiene configurada la facturación electrónica DIAN.');
        }

        $result = (new DianApiClient($config))->checkDocumentStatus($invoice->cufe);

        return $this->processResponse($invoice, $result);
    }

    /**
     * @return array{ok:bool, changed:bool, status:?string, message:string, status_code:?string}
     */
    protected function processResponse(SaleInvoice $invoice, array $result): array
    {
        $data = $result['data'] ?? [];
        $previousStatus = $invoice->dian_status;

        // Fallo de red / HTTP sin cuerpo: no tocamos el estado guardado, porque
        // no saber el estado no es lo mismo que estar rechazada.
        if (! $result['ok'] && empty($data)) {
            return [
                'ok' => false,
                'changed' => false,
                'status' => $previousStatus,
                'message' => $result['error'] ?? 'No fue posible consultar el estado en DIAN.',
                'status_code' => null,
            ];
        }

        // CUFE inexistente para esa empresa: success=false con mensaje.
        if (($data['success'] ?? null) === false) {
            return [
                'ok' => false,
                'changed' => false,
                'status' => $previousStatus,
                'message' => $data['message'] ?? 'DIAN no encontró el documento con ese CUFE.',
                'status_code' => null,
            ];
        }

        $dianResponse = $data['ResponseDian']['Envelope']['Body']['GetStatusZipResponse']['GetStatusZipResult'] ?? null;

        if (! $dianResponse) {
            return [
                'ok' => false,
                'changed' => false,
                'status' => $previousStatus,
                'message' => 'DIAN respondió sin datos de estado. Reintenta en unos minutos.',
                'status_code' => null,
            ];
        }

        $statusCode = (string) ($dianResponse['StatusCode'] ?? '');
        $isValid = filter_var($dianResponse['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // 10 = todavia en validacion. No es aceptada ni rechazada: se deja en
        // 'sent' para que una consulta posterior la resuelva.
        if (! $isValid && $statusCode === '10') {
            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_SENT,
                'dian_status_code' => $statusCode,
                'dian_response' => $data,
            ]);

            return [
                'ok' => true,
                'changed' => $previousStatus !== SaleInvoice::DIAN_SENT,
                'status' => SaleInvoice::DIAN_SENT,
                'message' => 'DIAN aún está validando el documento. Consulta de nuevo en unos minutos.',
                'status_code' => $statusCode,
            ];
        }

        if ($isValid) {
            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_ACCEPTED,
                'dian_status_code' => $statusCode,
                'qr_url' => $invoice->qr_url
                    ?: 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$invoice->cufe,
                'dian_error_message' => null,
                'dian_response' => $data,
            ]);

            return [
                'ok' => true,
                'changed' => $previousStatus !== SaleInvoice::DIAN_ACCEPTED,
                'status' => SaleInvoice::DIAN_ACCEPTED,
                'message' => (string) ($dianResponse['StatusMessage'] ?: 'DIAN confirma que el documento está autorizado.'),
                'status_code' => $statusCode,
            ];
        }

        $errorMsg = $this->extractError($dianResponse, $statusCode);

        $invoice->update([
            'dian_status' => SaleInvoice::DIAN_REJECTED,
            'dian_status_code' => $statusCode,
            'dian_error_message' => $errorMsg,
            'dian_response' => $data,
        ]);

        return [
            'ok' => true,
            'changed' => $previousStatus !== SaleInvoice::DIAN_REJECTED,
            'status' => SaleInvoice::DIAN_REJECTED,
            'message' => $errorMsg,
            'status_code' => $statusCode,
        ];
    }

    protected function extractError(array $dianResponse, string $statusCode): string
    {
        $errorMsg = $dianResponse['ErrorMessage']['string'] ?? null;

        if (is_array($errorMsg)) {
            return implode(' · ', array_map('strval', $errorMsg));
        }
        if (is_string($errorMsg) && $errorMsg !== '') {
            return $errorMsg;
        }

        $description = (string) ($dianResponse['StatusDescription'] ?? '');

        return $description !== ''
            ? $description.' (código '.$statusCode.')'
            : 'DIAN rechazó el documento (código '.$statusCode.').';
    }
}
