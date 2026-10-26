<!DOCTYPE html>
{{-- El sitio público de Pivox (CLAUDE.md secc. 40): la landing, el blog y los
     planes. Blade armado en el servidor, no Inertia: un buscador y la vista
     previa de una red social leen la página completa sin ejecutar JavaScript.
     Siempre con el tema Marino, el aspecto original de Pivox. --}}
<html lang="es-CR" class="site-root" data-company-theme="marino">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @include('site.partials.seo', ['seo' => $seo])

        <meta name="theme-color" content="#0b1f3a">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('images/logos/logo_pivox_corto.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="alternate" type="application/rss+xml" title="Blog de Pivox" href="{{ route('blog.feed') }}">

        <script>
            // El modo claro u oscuro es el mismo que en la aplicación
            // (pivox-theme): se aplica antes de pintar, sin parpadeo. «js»
            // habilita las animaciones de entrada: sin JavaScript, todo se ve.
            (function () {
                var root = document.documentElement;
                root.classList.add('js');
                try {
                    var stored = localStorage.getItem('pivox-theme');
                    if (stored === 'light' || stored === 'dark') root.setAttribute('data-theme', stored);
                } catch (e) {}
            })();
        </script>

        @fonts
        @vite(['resources/css/app.scss', 'resources/css/site.scss', 'resources/js/site/main.js'])
        @stack('head')
    </head>
    <body class="site">
        <a class="site-skip" href="#contenido">Saltar al contenido</a>

        @include('site.partials.header')

        <main id="contenido" tabindex="-1">
            @yield('content')
        </main>

        @include('site.partials.footer')
    </body>
</html>
