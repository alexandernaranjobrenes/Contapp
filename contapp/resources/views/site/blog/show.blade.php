@extends('site.layout')

@php
    $published = \App\Domains\Blog\Support\BlogPage::date($post->published_at);
    $updated = $post->published_at && $post->updated_at->diffInDays($post->published_at, true) >= 1
        ? \App\Domains\Blog\Support\BlogPage::date($post->updated_at)
        : null;
    $cover = $post->cover?->path ? $post->cover : null;
    $shareUrl = $post->url();
    $encodedUrl = rawurlencode($shareUrl);
    $encodedTitle = rawurlencode($post->title);
@endphp

@section('content')
    @if ($preview)
        <div class="blog-preview-banner" role="status">
            <x-site.icon name="eye" />
            <span>
                Vista previa.
                {{ $post->isPublished() ? 'Así se ve el artículo publicado.' : 'Es un borrador: todavía no se ve en el sitio.' }}
            </span>
            <a href="{{ route('backoffice.blog.edit', $post) }}">Volver al editor</a>
        </div>
    @endif

    <article class="article">
        <header class="article-header">
            <div class="site-container article-narrow">
                <nav class="site-breadcrumbs" aria-label="Migas de pan">
                    <a href="{{ route('home') }}">Inicio</a>
                    <x-site.icon name="chevron-right" />
                    <a href="{{ route('blog.index') }}">Blog</a>
                    @if ($post->category)
                        <x-site.icon name="chevron-right" />
                        <a href="{{ route('blog.category', $post->category->slug) }}">{{ $post->category->name }}</a>
                    @endif
                </nav>

                @if ($post->category)
                    <a href="{{ route('blog.category', $post->category->slug) }}" class="blog-tag">{{ $post->category->name }}</a>
                @endif

                <h1 class="article-title">{{ $post->title }}</h1>

                @if ($post->excerpt)
                    <p class="article-lead">{{ $post->excerpt }}</p>
                @endif

                <div class="article-meta">
                    <span><x-site.icon name="user-round" /> {{ $post->authorName() }}</span>
                    @if ($published)
                        <span><x-site.icon name="calendar" /> <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $published }}</time></span>
                    @endif
                    <span><x-site.icon name="clock" /> {{ $post->reading_minutes }} min de lectura</span>
                    @if ($updated)
                        <span>Actualizado el <time datetime="{{ $post->updated_at->toIso8601String() }}">{{ $updated }}</time></span>
                    @endif
                </div>
            </div>
        </header>

        @if ($cover)
            <figure class="site-container article-cover">
                <img
                    src="{{ $cover->url() }}"
                    alt="{{ $post->cover_alt ?: $post->title }}"
                    @if ($cover->width) width="{{ $cover->width }}" height="{{ $cover->height }}" @endif
                    fetchpriority="high"
                    decoding="async"
                >
            </figure>
        @endif

        <div @class(['site-container', 'article-layout', 'has-toc' => count($toc) >= 3])>
            @if (count($toc) >= 3)
                <aside class="article-toc">
                    <details open>
                        <summary><x-site.icon name="list" /> En este artículo</summary>
                        <nav aria-label="En este artículo">
                            <ol>
                                @foreach ($toc as $entry)
                                    <li @class(['is-sub' => $entry['level'] === 3])><a href="#{{ $entry['id'] }}">{{ $entry['text'] }}</a></li>
                                @endforeach
                            </ol>
                        </nav>
                    </details>
                </aside>
            @endif

            <div class="article-main">
                <div class="article-content">
                    {!! $html !!}
                </div>

                <div class="article-share" data-share="{{ $shareUrl }}" data-share-title="{{ $post->title }}">
                    <span class="article-share-label"><x-site.icon name="share-2" /> Compartir</span>
                    <a href="https://wa.me/?text={{ $encodedTitle }}%20{{ $encodedUrl }}" target="_blank" rel="noopener" class="btn btn-ghost"><x-site.icon name="message-circle" /> WhatsApp</a>
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ $encodedUrl }}" target="_blank" rel="noopener" class="btn btn-ghost">LinkedIn</a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ $encodedUrl }}" target="_blank" rel="noopener" class="btn btn-ghost">Facebook</a>
                    <a href="https://x.com/intent/post?url={{ $encodedUrl }}&amp;text={{ $encodedTitle }}" target="_blank" rel="noopener" class="btn btn-ghost">X</a>
                    <button type="button" class="btn btn-ghost" data-share-copy><x-site.icon name="link" /> Copiar enlace</button>
                    <button type="button" class="btn btn-ghost" data-share-native hidden><x-site.icon name="share-2" /> Más</button>
                    <span class="article-share-status" data-share-status role="status" aria-live="polite"></span>
                </div>

                <aside class="article-cta" aria-label="Pivox">
                    <span class="article-cta-icon"><img src="{{ asset('images/logos/logo_pivox_corto.png') }}" alt="" width="256" height="256" loading="lazy"></span>
                    <div>
                        <b>Llevá la contabilidad de tu empresa en Pivox</b>
                        <p>Contabilidad, facturación electrónica, inventario y planillas en un solo sistema, con Conti, su asistente con IA.</p>
                    </div>
                    <a href="{{ route('pricing.index') }}" class="btn btn-primary">Solicitar licencia</a>
                </aside>
            </div>
        </div>
    </article>

    @if ($related->isNotEmpty())
        <section class="site-section is-alt" aria-labelledby="related-title">
            <div class="site-container">
                <h2 id="related-title" class="site-h2 related-title">Seguí leyendo</h2>
                <div class="blog-grid">
                    @foreach ($related as $item)
                        @include('site.blog.card', ['post' => $item, 'heading' => 'h3'])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
