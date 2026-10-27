<?php

/*
|--------------------------------------------------------------------------
| Facturación electrónica — Ministerio de Hacienda (comprobantes v4.4)
|--------------------------------------------------------------------------
|
| Lo que es igual para todas las compañías. Lo de cada una (credenciales del
| ATV, llave criptográfica y su PIN, datos del emisor, ambiente) lo carga su
| Superusuario en Administración → Facturación electrónica y vive cifrado en
| la base (hacienda_settings, hacienda_credentials): nunca acá ni en el .env.
|
| Ver docs/einvoice/README.md y CLAUDE.md secc. 38.
|
*/

return [

    // Los dos ambientes de Hacienda. Cada compañía elige el suyo, y sus
    // credenciales y su llave de pruebas no sirven en producción (ni al revés).
    'environments' => [
        'stag' => [
            'label' => 'Pruebas',
            'idp_url' => env('HACIENDA_IDP_URL_STAG', 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token'),
            'api_url' => env('HACIENDA_API_URL_STAG', 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1'),
            'client_id' => 'api-stag',
        ],
        'prod' => [
            'label' => 'Producción',
            'idp_url' => env('HACIENDA_IDP_URL_PROD', 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token'),
            'api_url' => env('HACIENDA_API_URL_PROD', 'https://api.comprobanteselectronicos.go.cr/recepcion/v1'),
            'client_id' => 'api-prod',
        ],
    ],

    'timeout' => (int) env('HACIENDA_TIMEOUT', 25),
    'connect_timeout' => (int) env('HACIENDA_CONNECT_TIMEOUT', 10),

    // La hora que llevan FechaEmision y la clave: la de Costa Rica, aunque el
    // servidor corra en UTC.
    'timezone' => 'America/Costa_Rica',

    /*
    | ProveedorSistemas: «la cédula del proveedor de sistemas que se está
    | utilizando para emitir» (anotación del XSD). En un SaaS es la de quien
    | provee Pivox, no la de cada cliente. Vacío, se usa la del emisor (lo
    | correcto solo si el emisor desarrolla su propio sistema).
    */
    'system_provider_id' => env('HACIENDA_SYSTEM_PROVIDER_ID'),

    'xsd_path' => resource_path('hacienda/xsd/v4.4'),

    /*
    | Política de firma XAdES-EPES. El hash se calculó sobre los bytes del PDF
    | descargado de la URL oficial (2026-10-18), no se copió de otra fuente:
    |   openssl dgst -sha256 -binary politica.pdf | openssl base64
    | Si Hacienda publica otra política, cambian los dos valores juntos.
    */
    'signature_policy' => [
        'url' => 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/Resoluci%C3%B3n_General_sobre_disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos_para_efectos_tributarios.pdf',
        'digest_sha256' => 'DWxin1xWOeI8OuWQXazh4VjLWAaCLAA954em7DMh0h8=',
    ],

    // La cola de los envíos y las consultas. La atiende su propio worker
    // (docker-compose: queue-hacienda), separada de «backups», que puede tardar
    // una hora con un respaldo grande.
    'queue' => env('HACIENDA_QUEUE', 'hacienda'),

    /*
    | Cada cuánto se vuelve a preguntar por un comprobante que Hacienda todavía
    | procesa, en segundos. Después del último, el comprobante pasa a
    | «Requiere atención» en vez de consultarse para siempre (48 h en total).
    */
    'poll_backoff' => [20, 60, 120, 300, 900, 1800, 3600, 7200, 21600, 43200, 86400],

    // Reintentos de un envío que no se sabe si llegó (tiempo agotado, 5xx).
    // Antes de cada uno se consulta si Hacienda ya tiene la clave.
    'submit_backoff' => [60, 300, 900, 3600, 10800],

    // El API público de Hacienda (sin credenciales, solo lectura): catálogo
    // CAByS y actividades inscritas de una cédula. Si no contesta, nada se
    // bloquea (HaciendaPublicData). En las pruebas está apagado.
    'public_api' => [
        'enabled' => (bool) env('HACIENDA_PUBLIC_API_ENABLED', true),
        'url' => env('HACIENDA_PUBLIC_API_URL', 'https://api.hacienda.go.cr'),
        'timeout' => (int) env('HACIENDA_PUBLIC_API_TIMEOUT', 8),
    ],

    // Avisos al Superusuario antes de que venza la llave criptográfica.
    'certificate_warning_days' => [30, 15, 7, 1],
];
