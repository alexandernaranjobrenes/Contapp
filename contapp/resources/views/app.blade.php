<!DOCTYPE html>
{{-- El tema de la compañía activa (CLAUDE.md secc. 31) va desde el servidor,
     para que la primera pintura ya salga con sus colores. Al navegar lo
     actualiza app.js. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-company-theme="{{ $page['props']['companyTheme'] ?? 'marino' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- La aplicación y el backoffice no van a un buscador: lo que se
             indexa es el sitio público (site/layout.blade.php). --}}
        <meta name="robots" content="noindex, nofollow">

        <title inertia>{{ config('app.name', 'Pivox') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('images/logos/logo_pivox_corto.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <script>
            // La aplicación se llamaba Contapp, y el navegador guardó con ese
            // prefijo el tema, la barra lateral, las noticias vistas y el chat
            // de Conti. Se pasan una sola vez a las claves de Pivox, para que
            // nadie pierda lo que ya tenía.
            (function () {
                try {
                    [localStorage, sessionStorage].forEach(function (store) {
                        var keys = [];
                        for (var i = 0; i < store.length; i++) keys.push(store.key(i));
                        keys.forEach(function (key) {
                            if (! /^contapp[-:]/.test(key)) return;
                            var renamed = 'pivox' + key.slice('contapp'.length);
                            if (store.getItem(renamed) === null) store.setItem(renamed, store.getItem(key));
                            store.removeItem(key);
                        });
                    });
                } catch (e) {}
            })();

            // Aplica el tema guardado ANTES de pintar, para evitar el parpadeo
            // claro->oscuro en la primera carga.
            (function () {
                var stored = localStorage.getItem('pivox-theme');
                if (stored === 'light' || stored === 'dark') {
                    document.documentElement.setAttribute('data-theme', stored);
                }
            })();
        </script>

        @fonts
        @routes

        @vite(['resources/css/app.scss', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
