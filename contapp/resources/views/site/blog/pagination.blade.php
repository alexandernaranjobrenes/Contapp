{{-- La paginación del blog: enlaces de verdad (un buscador los sigue), con
     ?pagina=N y sin él en la primera. --}}
@if ($paginator->hasPages())
    @php
        $base = strtok(url()->current(), '?');
        $pageUrl = fn (int $page) => $page <= 1 ? $base : $base.'?pagina='.$page;
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
    @endphp

    <nav class="blog-pagination" aria-label="Páginas del blog">
        @if ($current > 1)
            <a href="{{ $pageUrl($current - 1) }}" class="btn btn-ghost" rel="prev"><x-site.icon name="arrow-left" /> Anteriores</a>
        @endif

        <ol>
            @for ($page = 1; $page <= $last; $page++)
                @if ($page === 1 || $page === $last || abs($page - $current) <= 1)
                    <li>
                        @if ($page === $current)
                            <span class="blog-page is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $pageUrl($page) }}" class="blog-page">{{ $page }}</a>
                        @endif
                    </li>
                @elseif (abs($page - $current) === 2)
                    <li><span class="blog-page is-gap" aria-hidden="true">…</span></li>
                @endif
            @endfor
        </ol>

        @if ($paginator->hasMorePages())
            <a href="{{ $pageUrl($current + 1) }}" class="btn btn-ghost" rel="next">Siguientes <x-site.icon name="arrow-right" /></a>
        @endif
    </nav>
@endif
