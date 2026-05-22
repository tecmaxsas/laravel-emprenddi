<?php

/*
|--------------------------------------------------------------------------
| Datos legales de la empresa
|--------------------------------------------------------------------------
|
| Datos que aparecen en las páginas públicas de Términos y Condiciones y
| Política de Privacidad. La empresa debe completarlos (preferiblemente
| vía variables de entorno) y revisar el contenido legal con su asesor
| jurídico antes de la publicación definitiva.
|
*/

return [
    'company_name' => env('LEGAL_COMPANY_NAME', 'Emprenddi'),
    'legal_name' => env('LEGAL_LEGAL_NAME', 'Emprenddi S.A.S.'),
    'nit' => env('LEGAL_NIT', ''),
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'contacto@emprenddi.com'),
    'contact_address' => env('LEGAL_CONTACT_ADDRESS', 'Colombia'),
    'jurisdiction_city' => env('LEGAL_JURISDICTION_CITY', 'Bogotá D.C.'),
    'updated_at' => env('LEGAL_UPDATED_AT', 'mayo de 2026'),
];
