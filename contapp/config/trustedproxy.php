<?php

/*
|--------------------------------------------------------------------------
| Los proxies de confianza (docs/produccion.md)
|--------------------------------------------------------------------------
|
| En producción el tráfico llega por Cloudflare: el visitante → Cloudflare →
| Hostinger. Sin esto, Laravel vería a todos con la IP de Cloudflare y con
| http://: el límite de solicitudes de /precios (5 por hora por IP) sería
| uno solo para todo el mundo, y los enlaces y la cookie de la sesión
| saldrían como si no hubiera HTTPS.
|
| Se confía en X-Forwarded-For y X-Forwarded-Proto solo cuando los manda uno
| de estos: una red interna (la del hosting, o la de Docker en desarrollo) y
| Cloudflare. NO en
| cualquiera ('*'): en Laravel eso confía en todas las IPs, y quien mande su
| propio X-Forwarded-For elegiría la IP con la que se lo cuenta. Así, la IP
| del visitante es la última de la cadena que no es de esta lista: la que
| agregó Cloudflare, no la que haya inventado el navegador.
|
| Los rangos de Cloudflare son los de https://www.cloudflare.com/ips/. Si
| publica otros, se agregan acá: mientras tanto, a un visitante que entre por
| un rango nuevo se le cuenta la IP de Cloudflare.
|
| El middleware TrustProxies de Laravel lee esta lista en cada request.
|
*/

return [

    'proxies' => [
        // Las redes internas (el hosting, Docker) y el propio servidor.
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '::1',
        'fc00::/7',

        // Cloudflare, IPv4.
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',

        // Cloudflare, IPv6.
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

];
