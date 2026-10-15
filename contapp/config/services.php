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

    /*
    |--------------------------------------------------------------------------
    | BCCR — Banco Central de Costa Rica
    |--------------------------------------------------------------------------
    |
    | El tipo de cambio sale de la API de indicadores económicos del BCCR
    | (SDDE), ver BccrSddeExchangeRateClient. El token se genera gratis en el
    | sitio de Indicadores Económicos del BCCR (registrarse, y en Mi perfil →
    | Generar token). Sin token, «Sincronizar con BCCR» no está disponible y
    | los tipos de cambio se cargan a mano.
    |
    | 'indicator': 318 es el tipo de cambio de referencia de venta del dólar
    | (el 317 es el de compra). Es el que usa Hacienda.
    |
    */
    'bccr' => [
        'token' => env('BCCR_TOKEN'),
        'base_url' => env('BCCR_BASE_URL', 'https://apim.bccr.fi.cr/SDDE/api/Bccr.GE.SDDE.Publico.Indicadores.API'),
        'indicator' => env('BCCR_INDICATOR', '318'),
        // El día del BCCR: «hoy» para la sincronización automática.
        'timezone' => 'America/Costa_Rica',
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI — el modelo de Conti, el asistente (CLAUDE.md secc. 32)
    |--------------------------------------------------------------------------
    |
    | Sin key, Conti no aparece. El modelo se puede cambiar sin tocar código;
    | su precio va en config/conti.php (los créditos se calculan con él).
    |
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

];
