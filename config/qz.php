<?php

/**
 * Configuración de QZ Tray (impresión local del cajero).
 *
 * Modo FIRMADO: si están configurados el certificado y la clave privada,
 * el navegador firma cada petición a QZ Tray con la clave (vía endpoint
 * server-side) y QZ Tray no vuelve a pedir permiso tras confiar el
 * certificado una vez.
 *
 * Modo SIN FIRMAR (fallback): si no hay certificado/clave configurados,
 * QZ funciona igual pero muestra el diálogo "Permitir" cada sesión.
 *
 * GENERAR EL PAR (una sola vez, en la VM):
 *   openssl genrsa -out qz-private-key.pem 2048
 *   openssl req -new -x509 -key qz-private-key.pem -out qz-certificate.txt \
 *           -days 3650 -subj "/CN=Emprenddi/O=Emprenddi"
 *
 * Después colocar:
 *   - qz-private-key.pem  → storage/app/qz/qz-private-key.pem  (NO commitear)
 *   - qz-certificate.txt  → storage/app/qz/qz-certificate.txt
 *
 * La clave privada NUNCA viaja al navegador — solo el servidor firma.
 */
return [
    // Rutas relativas a storage/app (disco 'local').
    'private_key_path' => env('QZ_PRIVATE_KEY_PATH', 'qz/qz-private-key.pem'),
    'certificate_path' => env('QZ_CERTIFICATE_PATH', 'qz/qz-certificate.txt'),

    // Algoritmo de firma — QZ Tray 2.1+ usa SHA512 por defecto.
    'algorithm' => env('QZ_SIGN_ALGORITHM', 'SHA512'),
];
