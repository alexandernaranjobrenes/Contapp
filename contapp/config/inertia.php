<?php

/*
|--------------------------------------------------------------------------
| Inertia: dónde están las páginas
|--------------------------------------------------------------------------
|
| Inertia 3 las busca por defecto en resources/js/pages (minúscula) y las de
| Pivox están en resources/js/Pages. Windows no distingue mayúsculas y las
| encontraba igual; en Linux (GitHub Actions, el servidor) las pruebas que
| revisan que la página exista (assertInertia → component) fallaban todas.
|
| Laravel combina este archivo con el del paquete solo en el primer nivel:
| «pages» va entero. Lo demás queda como lo trae el paquete.
|
*/

return [

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],

    ],

];
