<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints para la firma de peticiones de QZ Tray.
 *
 *  - GET  /qz/certificate : devuelve el certificado público (texto).
 *  - POST /qz/sign        : firma una cadena con la clave privada.
 *
 * La clave privada vive solo en el servidor (storage/app/qz/...). El
 * navegador nunca la ve — manda la cadena a firmar y recibe la firma.
 *
 * Si no hay certificado/clave configurados, /qz/certificate devuelve
 * vacío y el bridge JS cae a modo sin firmar automáticamente.
 *
 * Las rutas de config son relativas a storage/app/ — usamos storage_path()
 * directo (no Storage::disk('local'), cuya raíz en Laravel 12 es
 * storage/app/private).
 */
class QzSigningController extends Controller
{
    protected function resolvePath(?string $relative): ?string
    {
        if (! $relative) return null;
        $full = storage_path('app/'.ltrim($relative, '/'));
        return is_file($full) ? $full : null;
    }

    public function certificate(): Response
    {
        $path = $this->resolvePath(config('qz.certificate_path'));

        if (! $path) {
            // Sin certificado configurado → respuesta vacía → bridge usa unsigned.
            return response('', 200)->header('Content-Type', 'text/plain');
        }

        return response((string) file_get_contents($path), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function sign(Request $request): JsonResponse
    {
        $toSign = (string) $request->input('request', '');
        if ($toSign === '') {
            return response()->json(['signature' => '']);
        }

        $keyPath = $this->resolvePath(config('qz.private_key_path'));
        if (! $keyPath) {
            return response()->json([
                'signature' => '',
                'error' => 'QZ no configurado (sin clave privada).',
            ], 200);
        }

        $privateKey = openssl_pkey_get_private((string) file_get_contents($keyPath));
        if (! $privateKey) {
            return response()->json([
                'signature' => '',
                'error' => 'Clave privada QZ inválida.',
            ], 200);
        }

        $algo = match (strtoupper((string) config('qz.algorithm', 'SHA512'))) {
            'SHA1' => OPENSSL_ALGO_SHA1,
            'SHA256' => OPENSSL_ALGO_SHA256,
            default => OPENSSL_ALGO_SHA512,
        };

        $signature = '';
        $ok = openssl_sign($toSign, $signature, $privateKey, $algo);

        if (! $ok) {
            return response()->json([
                'signature' => '',
                'error' => 'No se pudo firmar la petición.',
            ], 200);
        }

        return response()->json(['signature' => base64_encode($signature)]);
    }
}
