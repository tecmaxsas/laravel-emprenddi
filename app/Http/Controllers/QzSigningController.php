<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

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
 */
class QzSigningController extends Controller
{
    public function certificate(): Response
    {
        $path = config('qz.certificate_path');

        if (! $path || ! Storage::disk('local')->exists($path)) {
            // Sin certificado configurado → respuesta vacía → bridge usa unsigned.
            return response('', 200)->header('Content-Type', 'text/plain');
        }

        return response(Storage::disk('local')->get($path), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function sign(Request $request): JsonResponse
    {
        $toSign = (string) $request->input('request', '');
        if ($toSign === '') {
            return response()->json(['signature' => '']);
        }

        $keyPath = config('qz.private_key_path');
        if (! $keyPath || ! Storage::disk('local')->exists($keyPath)) {
            return response()->json([
                'signature' => '',
                'error' => 'QZ no configurado (sin clave privada).',
            ], 200);
        }

        $privateKey = openssl_pkey_get_private(Storage::disk('local')->get($keyPath));
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
