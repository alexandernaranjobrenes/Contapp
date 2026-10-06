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
    | Credenciales del Servicio Web de Indicadores Económicos (se solicitan
    | gratis en https://www.bccr.fi.cr, sección "Servicios Web"). Sin ellas,
    | BccrSoapExchangeRateClient no puede autenticar y SyncBccrExchangeRatesService
    | simplemente no encontrará tipo de cambio para la fecha (source queda
    | manual hasta que se configuren).
    |
    | 'indicator_usd_venta' es el número de indicador BCCR para el tipo de
    | cambio de referencia de venta del dólar; confirmar el código vigente en
    | la documentación de BCCR antes de producción (suele ser el 317, pero el
    | Banco lo ha reordenado en el pasado).
    |
    */
    'bccr' => [
        'email' => env('BCCR_EMAIL'),
        'token' => env('BCCR_TOKEN'),
        'endpoint' => env('BCCR_ENDPOINT', 'https://gee.bccr.fi.cr/Indicadores/Suscripciones/WS/wsIndicadoresEconomicos.asmx/ObtenerIndicadoresEconomicos'),
        'indicator_usd_venta' => env('BCCR_INDICATOR_USD_VENTA', '317'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conti — el asistente (CLAUDE.md secc. 32)
    |--------------------------------------------------------------------------
    |
    | El chat de CONTAPP le pasa cada mensaje al flujo de n8n por su
    | Chat Trigger (modo «Embedded Chat», con autenticación básica). Sin
    | webhook_url, Conti no aparece. El usuario y la contraseña son los de la
    | autenticación básica del Chat Trigger: así solo CONTAPP puede hablarle.
    |
    */
    'conti' => [
        'webhook_url' => env('CONTI_WEBHOOK_URL'),
        'webhook_user' => env('CONTI_WEBHOOK_USER'),
        'webhook_password' => env('CONTI_WEBHOOK_PASSWORD'),
        // Lo que se espera la respuesta del agente, en segundos.
        'timeout' => (int) env('CONTI_TIMEOUT', 120),
    ],

];
