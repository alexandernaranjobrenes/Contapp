@extends('site.layout')

@section('content')
    <section class="blog-hero" aria-labelledby="blog-title">
        <div class="site-container">
            <nav class="site-breadcrumbs" aria-label="Migas de pan">
                <a href="{{ route('home') }}">Inicio</a>
                <x-site.icon name="chevron-right" />
                @if ($category)
                    <a href="{{ route('blog.index') }}">Blog</a>
                    <x-site.icon name="chevron-right" />
                    <span aria-current="page">{{ $category->name }}</span>
                @else
                    <span aria-current="page">Blog</span>
                @endif
            </nav>

            <p class="site-eyebrow"><x-site.icon name="newspaper" /> {{ $category ? 'Categoría' : 'Blog de Pivox' }}</p>
            <h1 id="blog-title" class="site-h1">{{ $category?->name ?? 'Contabilidad, impuestos y novedades de Pivox' }}</h1>
            <p class="site-lead">
                {{ $category?->description ?: 'Guías prácticas de contabilidad, IVA, facturación electrónica y planillas en Costa Rica, y todo lo nuevo de Pivox.' }}
            </p>

            @if ($categories->isNotEmpty())
                <nav class="blog-categories" aria-label="Categorías del blog">
                    <a href="{{ route('blog.index') }}" @class(['blog-chip', 'is-active' => ! $category]) @if (! $category) aria-current="page" @endif>Todos</a>
                    @foreach ($categories as $item)
                        <a
                            href="{{ route('blog.category', $item->slug) }}"
                            @class(['blog-chip', 'is-active' => $category?->is($item)])
                            @if ($category?->is($item)) aria-current="page" @endif
                        >{{ $item->name }} <span>{{ $item->posts_count }}</span></a>
                    @endforeach
                </nav>
            @endif
        </div>
    </section>

    <section class="site-section blog-list" aria-label="Artículos">
        <div class="site-container">
            @if ($posts->isEmpty())
                <div class="blog-empty">
                    <x-site.icon name="pen-line" :size="28" />
                    <p>Todavía no hay artículos publicados{{ $category ? ' en esta categoría' : '' }}. Volvé pronto.</p>
                    @if ($category)
                        <a href="{{ route('blog.index') }}" class="btn btn-ghost">Ver todo el blog</a>
                    @endif
                </div>
            @else
                @php
                    $featured = $posts->onFirstPage() && ! $category ? $posts->first() : null;
                    $rest = $featured ? $posts->slice(1) : $posts;
                @endphp

                @if ($featured)
                    @include('site.blog.card', ['post' => $featured, 'featured' => true, 'heading' => 'h2'])
                @endif

                @if ($rest->isNotEmpty())
                    <div class="blog-grid">
                        @foreach ($rest as $post)
                            @include('site.blog.card', ['post' => $post, 'featured' => false, 'heading' => 'h2'])
                        @endforeach
                    </div>
                @endif

                @include('site.blog.pagination', ['paginator' => $posts])
            @endif
        </div>
    </section>

    @include('site.home.cta')
@endsection
