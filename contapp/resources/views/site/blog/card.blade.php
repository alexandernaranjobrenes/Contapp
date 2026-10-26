{{-- La tarjeta de un artículo: en el índice, en una categoría, en la landing
     y en «Seguí leyendo». $featured la dibuja grande (el primero del índice);
     $heading, el nivel de su título. @include le pasa también las variables
     de la vista (en el índice, $featured es el artículo destacado): solo
     cuenta un true. --}}
@php
    $featured = ($featured ?? false) === true;
    $heading = in_array($heading ?? 'h2', ['h2', 'h3'], true) ? ($heading ?? 'h2') : 'h2';
    $url = route('blog.show', $post->slug);
    $cover = $post->cover?->path ? $post->cover : null;
@endphp

<article @class(['blog-card', 'is-featured' => $featured]) data-reveal>
    <a href="{{ $url }}" class="blog-card-media" tabindex="-1" aria-hidden="true">
        @if ($cover)
            <img
                src="{{ $cover->url() }}"
                alt=""
                @if ($cover->width) width="{{ $cover->width }}" height="{{ $cover->height }}" @endif
                loading="{{ $featured ? 'eager' : 'lazy' }}"
                decoding="async"
            >
        @else
            <span class="blog-card-placeholder"><x-site.icon name="newspaper" :size="32" /></span>
        @endif
    </a>

    <div class="blog-card-body">
        <div class="blog-meta">
            @if ($post->category)
                <a href="{{ route('blog.category', $post->category->slug) }}" class="blog-tag">{{ $post->category->name }}</a>
            @endif
            <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ \App\Domains\Blog\Support\BlogPage::date($post->published_at) }}</time>
            <span>{{ $post->reading_minutes }} min de lectura</span>
        </div>

        <{{ $heading }} class="blog-card-title"><a href="{{ $url }}">{{ $post->title }}</a></{{ $heading }}>
        <p class="blog-card-excerpt">{{ $post->excerpt ?: $post->metaDescription() }}</p>
    </div>
</article>
