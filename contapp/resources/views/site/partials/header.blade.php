@php
    $nav = [
        ['label' => 'Funciones', 'href' => route('home').'#funciones', 'active' => false],
        ['label' => 'Conti', 'href' => route('home').'#conti', 'active' => false],
        ['label' => 'Planes', 'href' => route('pricing.index'), 'active' => request()->routeIs('pricing.*')],
        ['label' => 'Blog', 'href' => route('blog.index'), 'active' => request()->routeIs('blog.*')],
    ];
    $signedIn = auth('web')->check();
@endphp

<header class="site-header" data-site-header>
    <div class="site-container site-header-inner">
        <a href="{{ route('home') }}" class="site-brand" aria-label="Pivox, ir al inicio">
            <img class="site-logo is-on-light" src="{{ asset('images/logos/logo_pivox.png') }}" alt="Pivox" width="554" height="160">
            <img class="site-logo is-on-dark" src="{{ asset('images/logos/logo_pivox_blanco.png') }}" alt="" width="554" height="160" aria-hidden="true">
        </a>

        <nav class="site-nav" aria-label="Principal">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}" @class(['site-nav-link', 'is-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="site-header-actions">
            <button type="button" class="site-icon-button site-theme-toggle" data-theme-toggle aria-label="Cambiar entre modo claro y oscuro" title="Modo claro u oscuro">
                <x-site.icon name="moon" :size="18" class="is-moon" />
                <x-site.icon name="sun" :size="18" class="is-sun" />
            </button>

            @if ($signedIn)
                <a href="{{ route('dashboard') }}" class="btn btn-ghost site-header-login"><x-site.icon name="layout-dashboard" /> Ir a mi panel</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-ghost site-header-login"><x-site.icon name="log-in" /> Iniciar sesión</a>
            @endif
            <a href="{{ route('pricing.index') }}" class="btn btn-primary site-header-cta">Solicitar licencia</a>

            <button
                type="button"
                class="site-icon-button site-menu-button"
                data-nav-toggle
                aria-controls="site-menu"
                aria-expanded="false"
                aria-label="Abrir menú"
            >
                <x-site.icon name="menu" :size="20" />
            </button>
        </div>
    </div>
</header>

{{-- El menú en un teléfono o una tableta: un cajón a la derecha que cierran
     Escape, la X, el fondo y cualquier enlace. --}}
<div class="site-drawer" id="site-menu" data-nav-drawer hidden>
    <div class="site-drawer-backdrop" data-nav-close></div>
    <div class="site-drawer-panel" role="dialog" aria-modal="true" aria-label="Menú">
        <div class="site-drawer-head">
            <img class="site-logo is-on-light" src="{{ asset('images/logos/logo_pivox.png') }}" alt="Pivox" width="554" height="160">
            <img class="site-logo is-on-dark" src="{{ asset('images/logos/logo_pivox_blanco.png') }}" alt="" width="554" height="160" aria-hidden="true">
            <button type="button" class="site-icon-button" data-nav-close aria-label="Cerrar menú"><x-site.icon name="x" :size="20" /></button>
        </div>

        <nav class="site-drawer-nav" aria-label="Principal">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}" @class(['is-active' => $item['active']]) data-nav-close>{{ $item['label'] }} <x-site.icon name="chevron-right" /></a>
            @endforeach
        </nav>

        <div class="site-drawer-actions">
            <a href="{{ route('pricing.index') }}" class="btn btn-primary btn-lg">Solicitar licencia</a>
            @if ($signedIn)
                <a href="{{ route('dashboard') }}" class="btn btn-ghost btn-lg"><x-site.icon name="layout-dashboard" /> Ir a mi panel</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-ghost btn-lg"><x-site.icon name="log-in" /> Iniciar sesión</a>
            @endif
            <a href="{{ route('license-activation.create') }}" class="site-drawer-link"><x-site.icon name="key-round" /> Ya tengo un código de licencia</a>
        </div>
    </div>
</div>
