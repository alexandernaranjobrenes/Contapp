{{-- Un QR de adorno (DemoCodes::decorativeQr): parece un QR, pero no lleva datos. --}}
@php($cells = \App\Domains\Site\Support\DemoCodes::decorativeQr(25, 'pivox-escanear'))
<svg class="demo-qr" viewBox="-1 -1 27 27" shape-rendering="crispEdges" aria-hidden="true">
    <rect x="-1" y="-1" width="27" height="27" class="demo-qr-bg" />
    @foreach ($cells as [$x, $y])
        <rect x="{{ $x }}" y="{{ $y }}" width="1" height="1" />
    @endforeach
</svg>
