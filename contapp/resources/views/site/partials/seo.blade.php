{{-- Título, descripción, canónica, Open Graph, Twitter y JSON-LD de una
     página del sitio (App\Domains\Site\Support\Seo). --}}
<title>{{ $seo->title }}</title>
<meta name="description" content="{{ $seo->description }}">
<link rel="canonical" href="{{ $seo->canonical }}">
<meta name="robots" content="{{ $seo->index ? 'index, follow, max-image-preview:large, max-snippet:-1' : 'noindex, follow' }}">
@if ($seo->prev)
    <link rel="prev" href="{{ $seo->prev }}">
@endif
@if ($seo->next)
    <link rel="next" href="{{ $seo->next }}">
@endif
@if (config('site.google_site_verification'))
    <meta name="google-site-verification" content="{{ config('site.google_site_verification') }}">
@endif

<meta property="og:site_name" content="{{ \App\Domains\Site\Support\Seo::SITE_NAME }}">
<meta property="og:locale" content="es_CR">
<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:title" content="{{ $seo->socialTitle ?? $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:url" content="{{ $seo->canonical }}">
<meta property="og:image" content="{{ $seo->imageUrl() }}">
<meta property="og:image:width" content="{{ $seo->imageWidth() }}">
<meta property="og:image:height" content="{{ $seo->imageHeight() }}">
<meta property="og:image:alt" content="{{ $seo->imageAlt() }}">
@if ($seo->type === 'article')
    @isset($seo->article['published_time'])
        <meta property="article:published_time" content="{{ $seo->article['published_time'] }}">
    @endisset
    @isset($seo->article['modified_time'])
        <meta property="article:modified_time" content="{{ $seo->article['modified_time'] }}">
    @endisset
    @if (! empty($seo->article['section']))
        <meta property="article:section" content="{{ $seo->article['section'] }}">
    @endif
    @if (! empty($seo->article['author']))
        <meta property="article:author" content="{{ $seo->article['author'] }}">
    @endif
    @foreach ($seo->article['tags'] ?? [] as $tag)
        <meta property="article:tag" content="{{ $tag }}">
    @endforeach
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo->socialTitle ?? $seo->title }}">
<meta name="twitter:description" content="{{ $seo->description }}">
<meta name="twitter:image" content="{{ $seo->imageUrl() }}">
<meta name="twitter:image:alt" content="{{ $seo->imageAlt() }}">

@if ($json = $seo->jsonLd())
    <script type="application/ld+json">{!! $json !!}</script>
@endif
