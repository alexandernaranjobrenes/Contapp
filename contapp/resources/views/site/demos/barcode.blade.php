{{-- El código de barras de un artículo, leído con la cámara (CLAUDE.md secc.
     33). El EAN-13 es de verdad: 744 es el prefijo de Costa Rica. --}}
@php($barcode = \App\Domains\Site\Support\DemoCodes::ean13('744123456789'))

<div class="demo barcode-demo" data-demo="barcode" aria-hidden="true">
    <div class="bd-label">
        <svg class="bd-bars" viewBox="0 0 95 40" preserveAspectRatio="none" shape-rendering="crispEdges">
            @foreach (str_split($barcode['modules']) as $index => $module)
                @if ($module === '1')
                    <rect x="{{ $index }}" y="0" width="1" height="40" />
                @endif
            @endforeach
        </svg>
        <span class="bd-digits">{{ substr($barcode['digits'], 0, 1) }} {{ substr($barcode['digits'], 1, 6) }} {{ substr($barcode['digits'], 7) }}</span>
        <span class="bd-laser"></span>
    </div>

    <div class="bd-result">
        <span class="bd-result-icon"><x-site.icon name="package" :size="18" /></span>
        <span class="bd-result-text">
            <b>Tornillo hexagonal 1/4"</b>
            <small>ART-0042 · 1.240 en existencia</small>
        </span>
        <x-site.icon name="circle-check" :size="18" class="bd-result-check" />
    </div>
</div>
