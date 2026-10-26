<?php

/*
|--------------------------------------------------------------------------
| El sitio público de Pivox (CLAUDE.md secc. 40)
|--------------------------------------------------------------------------
|
| La landing, el blog y la página de planes y precios. La dirección del sitio
| es APP_URL (en producción, https://pivoxcr.com): de ahí salen las URL
| canónicas, el sitemap y el robots.txt.
|
*/

return [

    // El correo de contacto que se muestra en el sitio, y al que van las
    // respuestas de quien recibe un correo sobre su solicitud de licencia.
    'contact_email' => env('SITE_CONTACT_EMAIL', 'servicioalcliente@pivoxcr.com'),

    // Adónde llega el aviso de una solicitud de licencia nueva, hasta que el
    // backoffice lo cambie en Planes y precios → Ajustes.
    'requests_email' => env('SITE_REQUESTS_EMAIL', 'servicios@ncodedigital.com'),

    // Quién hizo Pivox: el pie del sitio y el de las pantallas de acceso.
    'maker' => [
        'name' => 'NcodeDigital',
        'url' => 'https://ncodedigital.com',
    ],

    // La etiqueta de verificación de Google Search Console, si se usa.
    'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),

];
