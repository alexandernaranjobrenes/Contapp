@php($contact = config('site.contact_email'))
@php($maker = config('site.maker'))

<footer class="site-footer">
    <div class="site-container site-footer-grid">
        <div class="site-footer-brand">
            <img src="{{ asset('images/logos/logo_pivox_blanco.png') }}" alt="Pivox" width="554" height="160" loading="lazy">
            <p>Contabilidad, facturación electrónica, inventario y planillas para las empresas de Costa Rica, con Conti, su asistente con inteligencia artificial.</p>
            <a href="mailto:{{ $contact }}" class="site-footer-mail"><x-site.icon name="mail" /> {{ $contact }}</a>
        </div>

        <nav class="site-footer-nav" aria-label="Producto">
            <h2>Producto</h2>
            <a href="{{ route('home') }}#funciones">Funciones</a>
            <a href="{{ route('home') }}#conti">Conti, el asistente</a>
            <a href="{{ route('home') }}#facturacion-electronica">Facturación electrónica</a>
            <a href="{{ route('pricing.index') }}">Planes y precios</a>
        </nav>

        <nav class="site-footer-nav" aria-label="Recursos">
            <h2>Recursos</h2>
            <a href="{{ route('blog.index') }}">Blog</a>
            <a href="{{ route('license-activation.create') }}">Activar una licencia</a>
            @auth('web')
                <a href="{{ route('dashboard') }}">Ir a mi panel</a>
            @else
                <a href="{{ route('login') }}">Iniciar sesión</a>
            @endauth
            <a href="{{ route('privacy') }}">Privacidad</a>
        </nav>
    </div>

    <div class="site-container site-footer-bottom">
        <span>© {{ now()->year }} Pivox</span>
        <span>Hecho por <a href="{{ $maker['url'] }}" target="_blank" rel="noopener">{{ $maker['name'] }}</a></span>
    </div>
</footer>
