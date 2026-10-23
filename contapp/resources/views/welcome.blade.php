<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Pivox') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

        @fonts

        @vite(['resources/css/app.scss', 'resources/js/app.js'])
    </head>
    <body class="d-flex flex-column min-vh-100">
        <nav class="navbar navbar-expand navbar-dark bg-dark">
            <div class="container">
                <a class="navbar-brand fw-semibold" href="{{ url('/') }}">
                    <img src="{{ asset('images/logos/logo_pivox_blanco.png') }}" alt="{{ config('app.name', 'Pivox') }}" height="32">
                </a>

                @if (Route::has('login'))
                    <div class="d-flex gap-2">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-outline-light btn-sm">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-outline-light btn-sm">Iniciar sesión</a>

                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn btn-light btn-sm">Registrarse</a>
                            @endif
                        @endauth
                    </div>
                @endif
            </div>
        </nav>

        <main class="flex-grow-1 d-flex align-items-center">
            <div class="container py-5 text-center">
                <h1 class="display-5 fw-bold">{{ config('app.name', 'Pivox') }}</h1>
                <p class="lead text-body-secondary">Tu aplicación está lista y corriendo en Docker.</p>
            </div>
        </main>

        <footer class="text-center text-body-secondary py-3 border-top">
            <small>Laravel v{{ app()->version() }} &middot; PHP v{{ PHP_VERSION }}</small>
        </footer>
    </body>
</html>
