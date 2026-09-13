<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'CONTAPP') }}</title>

        <script>
            // Aplica el tema guardado ANTES de pintar, para evitar el parpadeo
            // claro->oscuro en la primera carga.
            (function () {
                var stored = localStorage.getItem('contapp-theme');
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
