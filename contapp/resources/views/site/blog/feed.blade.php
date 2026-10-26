{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
    <channel>
        <title>Blog de Pivox</title>
        <link>{{ \App\Domains\Site\Support\Seo::route('blog.index') }}</link>
        <atom:link href="{{ \App\Domains\Site\Support\Seo::route('blog.feed') }}" rel="self" type="application/rss+xml" />
        <description>Contabilidad, impuestos, facturación electrónica y planillas en Costa Rica, y las novedades de Pivox.</description>
        <language>es-CR</language>
@if ($posts->isNotEmpty())
        <lastBuildDate>{{ $posts->max('updated_at')->toRfc2822String() }}</lastBuildDate>
@endif
@foreach ($posts as $post)
        <item>
            <title>{{ $post->title }}</title>
            <link>{{ $post->url() }}</link>
            {{-- Fijo aunque cambie la dirección: un lector no lo muestra dos veces. --}}
            <guid isPermaLink="false">pivox-blog-{{ $post->id }}</guid>
            <pubDate>{{ $post->published_at->toRfc2822String() }}</pubDate>
@if ($post->category)
            <category>{{ $post->category->name }}</category>
@endif
            <description>{{ $post->metaDescription() }}</description>
@if ($post->cover?->path)
            <media:content url="{{ $post->cover->url() }}" medium="image"@if ($post->cover->width) width="{{ $post->cover->width }}" height="{{ $post->cover->height }}"@endif />
@endif
        </item>
@endforeach
    </channel>
</rss>
