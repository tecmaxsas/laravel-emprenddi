<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use App\Models\SaleInvoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orquesta el envío de una factura de venta contabilizada a apidian.
 *
 * Flujo:
 *  1. Verifica precondiciones (factura posted, empresa configurada en DIAN).
 *  2. Construye el payload UBL con SaleInvoiceUblBuilder.
 *  3. Llama DianApiClient::sendInvoice().
 *  4. Persiste CUFE/QR/status/respuesta en sale_invoices.
 *  5. Devuelve resultado normalizado para la UI.
 */
class DianInvoiceSender
{
    public function __construct(
        protected SaleInvoiceUblBuilder $builder,
    ) {}

    public function send(SaleInvoice $invoice): array
    {
        if (! $invoice->isPosted()) {
            throw new RuntimeException('Solo se pueden enviar a DIAN facturas contabilizadas.');
        }

        $config = CompanyConfig::query()->where('company_id', $invoice->company_id)->first();

        if (! $config || ! $config->company_registered) {
            throw new RuntimeException('La empresa no ha completado el registro DIAN. Ve a Administración → Facturación Electrónica DIAN.');
        }

        if (! $config->api_token) {
            throw new RuntimeException('Falta el token DIAN. Completa el Tab 1 de Facturación Electrónica DIAN.');
        }

        $payload = $this->builder->build($invoice);

        // Marca enviado antes de la llamada — si peta el HTTP, queda registrado el intento
        $invoice->update([
            'dian_status' => SaleInvoice::DIAN_SENT,
            'dian_sent_at' => now(),
        ]);

        $client = new DianApiClient($config);
        $result = $client->sendInvoice($payload);

        return $this->processResponse($invoice, $result);
    }

    /**
     * Normaliza respuesta de apidian + actualiza la factura con CUFE/status.
     * Devuelve ['ok' => bool, 'message' => string, 'cufe' => ?string, 'status_code' => ?string]
     */
    protected function processResponse(SaleInvoice $invoice, array $result): array
    {
        $data = $result['data'] ?? [];

        // Errores HTTP / conexión
        if (! $result['ok'] && empty($data)) {
            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_REJECTED,
                'dian_error_message' => $result['error'] ?? 'Error de conexión con apidian',
                'dian_response' => $result,
            ]);
            return [
                'ok' => false,
                'message' => $result['error'] ?? 'Error de conexión',
                'cufe' => null,
                'status_code' => null,
            ];
        }

        // Errores de validación pre-DIAN (campo errors en el payload)
        if (isset($data['errors'])) {
            $messages = $this->flattenErrors($data['errors']);
            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_REJECTED,
                'dian_error_message' => $messages,
                'dian_response' => $data,
            ]);
            return [
                'ok' => false,
                'message' => 'Errores de validación: '.$messages,
                'cufe' => null,
                'status_code' => null,
            ];
        }

        // Excepción interna del API
        if (isset($data['exception'])) {
            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_REJECTED,
                'dian_error_message' => 'Excepción: '.($data['exception'] ?? 'desconocida'),
                'dian_response' => $data,
            ]);
            return [
                'ok' => false,
                'message' => 'Excepción del API: '.($data['exception'] ?? ''),
                'cufe' => null,
                'status_code' => null,
            ];
        }

        // Respuesta DIAN (caso normal)
        $dianResponse = $data['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult'] ?? null;
        $cufe = $data['cufe'] ?? null;

        if (! $dianResponse) {
            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_REJECTED,
                'dian_error_message' => 'Sin respuesta de DIAN. Reintenta en unos minutos.',
                'dian_response' => $data,
            ]);
            return [
                'ok' => false,
                'message' => 'Sin respuesta de DIAN',
                'cufe' => $cufe,
                'status_code' => null,
            ];
        }

        $statusCode = (string) ($dianResponse['StatusCode'] ?? '');
        $statusDescription = (string) ($dianResponse['StatusDescription'] ?? '');
        $isValid = filter_var($dianResponse['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isValid && $cufe) {
            // Aceptado — guardar CUFE y construir QR URL
            $qrUrl = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufe;

            $invoice->update([
                'dian_status' => SaleInvoice::DIAN_ACCEPTED,
                'dian_status_code' => $statusCode,
                'cufe' => $cufe,
                'qr_url' => $qrUrl,
                'dian_error_message' => null,
                'dian_response' => $data,
            ]);

            return [
                'ok' => true,
                'message' => 'Factura aceptada por DIAN',
                'cufe' => $cufe,
                'status_code' => $statusCode,
            ];
        }

        // Rechazada o caso especial (90/99 = ya emitida, 115 = consec usado, etc.)
        $errorMsg = $this->extractDianError($dianResponse, $statusCode);

        $invoice->update([
            'dian_status' => SaleInvoice::DIAN_REJECTED,
            'dian_status_code' => $statusCode,
            'cufe' => $cufe,  // a veces viene aunque IsValid=false
            'dian_error_message' => $errorMsg,
            'dian_response' => $data,
        ]);

        return [
            'ok' => false,
            'message' => $errorMsg,
            'cufe' => $cufe,
            'status_code' => $statusCode,
        ];
    }

    protected function flattenErrors(array $errors): string
    {
        $msgs = [];
        foreach ($errors as $field => $errorList) {
            if (is_array($errorList)) {
                foreach ($errorList as $msg) {
                    $msgs[] = (string) $msg;
                }
            } else {
                $msgs[] = (string) $errorList;
            }
        }
        return implode(' · ', $msgs);
    }

    protected function extractDianError(array $dianResponse, string $statusCode): string
    {
        $specialMessages = [
            '115' => 'El consecutivo de la factura ya está registrado en DIAN.',
            '117' => 'La fecha/hora de emisión no debe ser mayor a la fecha del sistema.',
            '90' => 'Documento ya emitido anteriormente.',
            '99' => 'Documento ya emitido anteriormente.',
        ];

        if (isset($specialMessages[$statusCode])) {
            return $specialMessages[$statusCode];
        }

        $errorMsg = $dianResponse['ErrorMessage']['string'] ?? null;
        if (is_array($errorMsg)) {
            return implode(' · ', $errorMsg);
        }
        if (is_string($errorMsg)) {
            return $errorMsg;
        }

        return ($dianResponse['StatusDescription'] ?? '').' (código '.$statusCode.')';
    }
}
