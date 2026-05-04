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
                'dian_error_message' => 'Excepción: '.$data['exception'],
                'dian_response' => $data,
            ]);
            return ['ok' => false, 'message' => 'Excepción del API', 'cufe' => null, 'status_code' => null];
        }

        $dianResponse = $data['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult'] ?? null;
        $cufe = $data['cufe'] ?? null;

        if (! $dianResponse) {
            $note->update([
                'dian_status' => CreditDebitNote::DIAN_REJECTED,
                'dian_error_message' => 'Sin respuesta de DIAN.',
                'dian_response' => $data,
            ]);
            return ['ok' => false, 'message' => 'Sin respuesta DIAN', 'cufe' => $cufe, 'status_code' => null];
        }

        $statusCode = (string) ($dianResponse['StatusCode'] ?? '');
        $isValid = filter_var($dianResponse['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isValid && $cufe) {
            $qrUrl = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufe;
            $note->update([
                'dian_status' => CreditDebitNote::DIAN_ACCEPTED,
                'dian_status_code' => $statusCode,
                'cufe' => $cufe,
                'qr_url' => $qrUrl,
                'dian_error_message' => null,
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

    protected function flattenErrors(array $errors): string
    {
        $msgs = [];
        foreach ($errors as $errorList) {
            if (is_array($errorList)) {
                foreach ($errorList as $msg) $msgs[] = (string) $msg;
            } else {
                $msgs[] = (string) $errorList;
            }
        }
        return implode(' · ', $msgs);
    }

    protected function extractDianError(array $dianResponse, string $statusCode): string
    {
        $special = [
            '115' => 'Consecutivo ya registrado en DIAN.',
            '117' => 'Fecha mayor a la del sistema.',
            '90' => 'Documento ya emitido.',
            '99' => 'Documento ya emitido.',
        ];

        if (isset($special[$statusCode])) return $special[$statusCode];

        $errorMsg = $dianResponse['ErrorMessage']['string'] ?? null;
        if (is_array($errorMsg)) return implode(' · ', $errorMsg);
        if (is_string($errorMsg)) return $errorMsg;

        return ($dianResponse['StatusDescription'] ?? '').' (código '.$statusCode.')';
    }
}
