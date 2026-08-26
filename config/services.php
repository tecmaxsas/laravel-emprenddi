<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Contacto de soporte que muestra el boton flotante de WhatsApp.
    // En .env se puede cambiar sin tocar codigo: SUPPORT_WHATSAPP=57...
    'support' => [
        'whatsapp' => env('SUPPORT_WHATSAPP', '573246415947'),
    ],

    'dian' => [
        // URL del API UBL 2.1 (apidian.emprenddi.com de Tecmax).
        'api_url' => env('DIAN_API_URL', 'https://apidian.emprenddi.com'),
    ],

];
