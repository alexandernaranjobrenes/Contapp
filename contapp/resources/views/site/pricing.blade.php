@extends('site.layout')

@php($contact = config('site.contact_email'))

@section('content')
    <section class="pricing-hero" aria-labelledby="pricing-title">
        <div class="site-container">
            <nav class="site-breadcrumbs" aria-label="Migas de pan">
                <a href="{{ route('home') }}">Inicio</a>
                <x-site.icon name="chevron-right" />
                <span aria-current="page">Planes y precios</span>
            </nav>

            <p class="site-eyebrow"><x-site.icon name="key-round" /> Planes y precios</p>
            <h1 id="pricing-title" class="site-h1">Armá el plan que tu empresa necesita</h1>
            <p class="site-lead">
                Elegí un paquete y ajustalo: agregá o quitá funciones y mirá el precio al instante.
                Se paga por mes o por año, en colones o en dólares.
            </p>
            <p class="pricing-activate">
                <x-site.icon name="key-round" /> ¿Ya tenés un código de licencia? <a href="{{ route('license-activation.create') }}">Activala acá</a>.
            </p>
        </div>
    </section>

    @if ($cards->isNotEmpty())
        <section class="site-section pricing-packages" aria-label="Paquetes">
            <div class="site-container">
                <div class="package-grid">
                    @foreach ($cards as $card)
                        <article @class(['package-card', 'is-highlighted' => $card['highlighted']]) data-reveal>
                            @if ($card['badge'])
                                <span class="package-badge">{{ $card['badge'] }}</span>
                            @endif

                            <h2 class="package-name">{{ $card['name'] }}</h2>
                            @if ($card['tagline'])
                                <p class="package-tagline">{{ $card['tagline'] }}</p>
                            @endif

                            <p class="package-price">
                                <b>{{ $card['price'] }}</b>
                                <span>{{ \App\Domains\Pricing\Support\PricingMoney::cycle($card['cycle']) }}{{ $settings->prices_include_tax ? '' : ' + IVA' }}</span>
                            </p>
                            <p class="package-billing">
                                @switch($card['billing'])
                                    @case('monthly') Pago mensual @break
                                    @case('annual') Pago anual @break
                                    @default Pago mensual o anual
                                @endswitch
                                @if ($card['discount_bp'] > 0)
                                    · <strong>{{ rtrim(rtrim(number_format($card['discount_bp'] / 100, 2, ',', '.'), '0'), ',') }} % de descuento</strong>
                                @endif
                            </p>

                            @if ($card['description'])
                                <p class="package-description">{{ $card['description'] }}</p>
                            @endif

                            <ul class="package-items">
                                @foreach ($card['items'] as $item)
                                    <li><x-site.icon name="check" /> {{ $item }}</li>
                                @endforeach
                            </ul>

                            <a
                                href="{{ route('pricing.index', ['paquete' => $card['slug']]) }}#configurador"
                                @class(['btn', 'btn-lg', 'package-cta', 'btn-primary' => $card['highlighted'], 'btn-ghost' => ! $card['highlighted']])
                                data-package="{{ $card['slug'] }}"
                            >Personalizar y solicitar</a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section id="configurador" class="site-section is-alt pricing-builder" aria-labelledby="builder-title">
        <div class="site-container">
            <div class="site-section-head">
                <p class="site-eyebrow"><x-site.icon name="sparkles" /> Configurador</p>
                <h2 id="builder-title" class="site-h2">Ajustalo a tu presupuesto</h2>
                <p class="site-lead">
                    Agregá o quitá funciones, compañías y usuarios. Si una función necesita otra para servir, se suma sola.
                </p>
            </div>

            @if (count($catalog['features']))
                <div
                    data-island="pricing"
                    data-preselect="{{ $preselect }}"
                    data-started="{{ $started }}"
                    data-request-url="{{ route('license-requests.store') }}"
                    data-privacy-url="{{ route('privacy') }}"
                    data-contact="{{ $contact }}"
                >
                    <p class="pricing-loading"><x-site.icon name="loader-circle" /> Cargando el configurador…</p>
                </div>
                <script type="application/json" id="pricing-catalog">@json($catalog)</script>
                <noscript>
                    <p class="flash flash-info">
                        Para armar tu plan hace falta JavaScript. También podés escribirnos a <a href="mailto:{{ $contact }}">{{ $contact }}</a> y te armamos uno.
                    </p>
                </noscript>
            @else
                <div class="pricing-empty">
                    <x-site.icon name="mail" :size="28" />
                    <p>Pronto vas a encontrar acá los planes de Pivox. Mientras tanto, escribinos a <a href="mailto:{{ $contact }}">{{ $contact }}</a> y te armamos uno a tu medida.</p>
                </div>
            @endif
        </div>
    </section>

    <section class="site-section pricing-faq" aria-labelledby="faq-title">
        <div class="site-container pricing-faq-inner">
            <h2 id="faq-title" class="site-h2">Preguntas frecuentes</h2>
            <div class="faq-list">
                @foreach ($faq as $item)
                    <details class="faq-item">
                        <summary>{{ $item['question'] }} <x-site.icon name="chevron-down" /></summary>
                        <p>{{ $item['answer'] }}</p>
                    </details>
                @endforeach
            </div>
            <p class="faq-contact">¿Otra pregunta? Escribinos a <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>
        </div>
    </section>
@endsection
