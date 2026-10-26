{{-- Portada: fondo profundo con la retícula de un libro contable y el brillo
     del acento, como el panel de las pantallas de acceso (AuthShell). --}}
<section class="site-hero" aria-labelledby="hero-title">
    <div class="site-container site-hero-grid">
        <div class="site-hero-copy">
            <p class="site-eyebrow is-on-dark"><x-site.icon name="sparkles" /> Pensado para las empresas de Costa Rica</p>
            <h1 id="hero-title" class="site-hero-title">Contabilidad, facturación electrónica y planillas, en un solo sistema</h1>
            <p class="site-hero-lead">
                Pivox lleva la contabilidad de tus compañías, emite tus comprobantes ante Hacienda, controla el inventario
                y calcula la planilla con la CCSS. Y Conti, su asistente con inteligencia artificial, prepara los registros por vos.
            </p>

            <div class="site-hero-actions">
                <a href="{{ route('pricing.index') }}" class="btn btn-lg btn-accent">Solicitar licencia <x-site.icon name="arrow-right" /></a>
                <a href="#conti" class="btn btn-lg btn-on-dark">Ver cómo funciona</a>
            </div>

            <ul class="site-hero-points">
                <li><x-site.icon name="circle-check" /> Comprobantes electrónicos v4.4</li>
                <li><x-site.icon name="circle-check" /> Tipo de cambio del BCCR, todos los días</li>
                <li><x-site.icon name="circle-check" /> Planilla con la CCSS y el impuesto al salario</li>
            </ul>
        </div>

        <div class="site-hero-visual">
            @include('site.home.app-mock')
        </div>
    </div>
</section>
