<?php

namespace App\Services\Dian;

use App\Models\Dian\CompanyConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para apidian.emprenddi.com (UBL 2.1, DIAN Colombia).
 *
 * Cada empresa tiene su propio api_token (lo entrega Tecmax al activar la empresa
 * en su panel) y se persiste en dian_company_configs. El api_url default es
 * https://apidian.emprenddi.com pero se puede sobreescribir por empresa
 * (útil para staging).
 *
 * Todos los métodos retornan ['ok' => bool, 'data' => array, 'status' => int,
 *   'error' => ?string] — nunca lanzan excepciones para que la UI pueda mostrar
 * el error de DIAN sin que se rompa la página.
 */
class DianApiClient
{
    public function __construct(
        protected CompanyConfig $config,
    ) {}

    /**
     * Tab 1 — Datos de la empresa.
     * Registra/actualiza la empresa contra el API. La URL incluye NIT y DV en path.
     *
     * IMPORTANTE: este endpoint NO requiere auth. El API retorna el token
     * per-company en la respuesta y se persiste para usarlo en todos los demás
     * endpoints (Software, Certificado, Resoluciones, Pruebas).
     *
     * @param  string  $document  NIT sin DV ni separadores
     * @param  string|int  $dv  Dígito de verificación
     */
    public function registerCompany(string $document, string|int $dv, array $payload): array
    {
        return $this->request(
            'post',
            "/api/ubl2.1/config/{$document}/{$dv}",
            $payload,
            authenticated: false,
        );
    }

    /**
     * Tab 2 — Software DIAN (id + pin entregados por DIAN al habilitar).
     */
    public function saveSoftware(array $payload): array
    {
        return $this->request('put', '/api/ubl2.1/config/software', $payload);
    }

    /**
     * Tab 3 — Certificado .p12 (base64-encoded).
     */
    public function uploadCertificate(array $payload): array
    {
        return $this->request('put', '/api/ubl2.1/config/certificate', $payload);
    }

    /**
     * Tab 4 — Resolución de numeración DIAN.
     */
    public function saveResolution(array $payload): array
    {
        return $this->request('put', '/api/ubl2.1/config/resolution', $payload);
    }

    /**
     * Tab 4 — Consulta rangos de numeración existentes en DIAN.
     */
    public function getNumberRanges(array $payload = []): array
    {
        return $this->request('post', '/api/ubl2.1/numbering-range', $payload);
    }

    /**
     * Envía una factura electrónica real (no de prueba) a DIAN vía apidian.
     * Endpoint /api/ubl2.1/invoice — devuelve CUFE + ResponseDian con el
     * resultado de la validación de DIAN.
     *
     * @param  array  $payload  construido por SaleInvoiceUblBuilder
     */
    public function sendInvoice(array $payload): array
    {
        return $this->request('post', '/api/ubl2.1/invoice', $payload);
    }

    /**
     * Tab 5 — Cambiar entre Pruebas (2) y Producción (1).
     * El API requiere los 3 IDs de ambiente (factura, nómina, equivalentes).
     */
    public function changeEnvironment(int $environment): array
    {
        return $this->request('put', '/api/ubl2.1/config/environment', [
            'type_environment_id' => $environment,
            'payroll_type_environment_id' => $environment,
            'eqdocs_type_environment_id' => $environment,
        ]);
    }

    /**
     * Tab 5 — Envía una factura del set de pruebas DIAN para habilitar producción.
     */
    public function sendTestInvoice(array $payload, string $testSetId): array
    {
        return $this->request('post', "/api/ubl2.1/invoice/{$testSetId}", $payload);
    }

    protected function client(bool $authenticated = true): PendingRequest
    {
        $baseUrl = $this->config->api_url ?: config('services.dian.api_url');

        $client = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(15);

        if ($authenticated && $this->config->api_token) {
            $client = $client->withToken($this->config->api_token);
        }

        return $client;
    }

    protected function request(string $method, string $endpoint, array $payload, bool $authenticated = true): array
    {
        try {
            /** @var Response $response */
            $response = $this->client($authenticated)->{$method}($endpoint, $payload);

            $data = $response->json() ?? [];

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'data' => $data,
                'error' => $response->successful() ? null : ($data['message'] ?? "HTTP {$response->status()}"),
            ];
        } catch (\Throwable $e) {
            Log::warning('DianApiClient request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'company_id' => $this->config->company_id,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => 'No fue posible conectarse a apidian.emprenddi.com: '.$e->getMessage(),
            ];
        }
    }
}
