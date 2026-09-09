<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente de la API de mensajes de Anthropic.
 *
 * Devuelve siempre un arreglo `['ok' => bool, …]` y nunca lanza excepciones: la
 * pantalla de chat tiene que poder mostrar «Anthropic respondió esto» sin que se
 * caiga la página, igual que hace DianApiClient con la DIAN.
 */
class AnthropicClient
{
    public function __construct(private readonly string $apiKey) {}

    /**
     * Una vuelta de conversación.
     *
     * @param  list<array<string, mixed>>  $mensajes  historial en formato Anthropic
     * @param  list<array<string, mixed>>  $herramientas
     * @return array{ok: bool, data?: array, error?: string, status?: int}
     */
    public function mensajes(
        string $modelo,
        string $sistema,
        array $mensajes,
        array $herramientas = [],
        int $maxTokens = 4096,
    ): array {
        $payload = array_filter([
            'model' => $modelo,
            'max_tokens' => $maxTokens,
            'system' => $sistema,
            'messages' => $mensajes,
            'tools' => $herramientas ?: null,
        ], fn ($v) => $v !== null);

        try {
            $respuesta = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => config('ai.version'),
                'content-type' => 'application/json',
            ])
                ->timeout(config('ai.timeout'))
                ->post(config('ai.api_url'), $payload);
        } catch (Throwable $e) {
            Log::warning('[Claude] No se pudo llamar a la API: '.$e->getMessage());

            return [
                'ok' => false,
                'error' => 'No se pudo conectar con Claude. Revisa la conexión del servidor e inténtalo de nuevo.',
            ];
        }

        if ($respuesta->successful()) {
            return ['ok' => true, 'data' => $respuesta->json(), 'status' => $respuesta->status()];
        }

        return [
            'ok' => false,
            'status' => $respuesta->status(),
            'error' => $this->mensajeDeError($respuesta->status(), $respuesta->json()),
        ];
    }

    /**
     * El error de Anthropic traducido a algo que un comerciante pueda entender
     * y actuar. «invalid_request_error» no le dice a nadie qué hacer.
     */
    private function mensajeDeError(int $estado, ?array $cuerpo): string
    {
        $detalle = data_get($cuerpo, 'error.message');

        return match ($estado) {
            401 => 'La llave de Anthropic no es válida. Revísala en la configuración.',
            403 => 'La llave de Anthropic no tiene permiso para usar este modelo.',
            429 => 'Anthropic está limitando las peticiones. Espera un momento e inténtalo de nuevo.',
            400 => 'Anthropic rechazó la petición'.($detalle ? ': '.$detalle : '.'),
            500, 502, 503, 529 => 'Anthropic está teniendo problemas en este momento. Inténtalo en unos minutos.',
            default => 'Claude respondió con un error'.($detalle ? ': '.$detalle : " (código {$estado}).")
        };
    }
}
