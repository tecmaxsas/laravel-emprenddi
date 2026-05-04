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

    'dian' => [
        // URL del API UBL 2.1 (apidian.emprenddi.com de Tecmax).
        'api_url' => env('DIAN_API_URL', 'https://apidian.emprenddi.com'),

        // Token "master" usado SOLO para el primer registerCompany (Tab 1).
        // El API responde con un token per-company que se persiste en
        // dian_company_configs.api_token y se usa para todo lo demás.
        'master_token' => env('DIAN_MASTER_TOKEN'),
    ],

];
