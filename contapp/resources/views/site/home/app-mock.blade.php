{{-- Una maqueta del Panel de Pivox, hecha con los mismos tokens de la
     aplicación: en modo oscuro se ve como la aplicación en modo oscuro. Es
     decorativa (aria-hidden); lo que dice está en el texto de al lado. --}}
@php
    $menu = ['layout-dashboard', 'book-open', 'tags', 'package', 'receipt', 'users', 'handshake', 'landmark', 'percent'];
    $bars = [[62, 40], [70, 46], [58, 42], [80, 51], [74, 47], [92, 55]];
    $months = ['may', 'jun', 'jul', 'ago', 'sep', 'oct'];
@endphp

<div class="mock" aria-hidden="true">
    <div class="mock-window">
        <div class="mock-sidebar">
            <span class="mock-brand"><img src="{{ asset('images/logos/logo_pivox_corto.png') }}" alt="" width="256" height="256"></span>
            @foreach ($menu as $index => $icon)
                <span @class(['mock-nav', 'is-active' => $index === 0])><x-site.icon :name="$icon" :size="15" /></span>
            @endforeach
        </div>

        <div class="mock-main">
            <div class="mock-topbar">
                <span class="mock-title">Panel</span>
                <span class="mock-company"><x-site.icon name="building-2" :size="12" /> Distribuidora La Sabana</span>
                <span class="mock-role">Superusuario</span>
            </div>

            <div class="mock-content">
                <div class="mock-stats">
                    <div class="mock-stat"><span>Cuentas en el catálogo</span><b data-count-to="214">214</b></div>
                    <div class="mock-stat"><span>Asientos contabilizados</span><b data-count-to="1286">1.286</b></div>
                    <div class="mock-stat"><span>Partidas abiertas</span><b data-count-to="37">37</b></div>
                </div>

                <div class="mock-chart">
                    <div class="mock-chart-head">
                        <span>Ingresos y gastos</span>
                        <span class="mock-legend"><i class="is-in"></i> Ingresos <i class="is-out"></i> Gastos</span>
                    </div>
                    <div class="mock-bars">
                        @foreach ($bars as $index => [$in, $out])
                            <div class="mock-bar-group" style="--in: {{ $in }}%; --out: {{ $out }}%; --i: {{ $index }}">
                                <i class="is-in"></i><i class="is-out"></i>
                            </div>
                        @endforeach
                    </div>
                    <div class="mock-months">
                        @foreach ($months as $month)
                            <span>{{ $month }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="mock-docs">
                    <div class="mock-doc"><b>AS-0128</b><span>Pago de alquiler</span><em class="badge badge-success">Contabilizado</em></div>
                    <div class="mock-doc"><b>FE-00045</b><span>Factura electrónica</span><em class="badge badge-success">Aceptada</em></div>
                    <div class="mock-doc"><b>OC-0031</b><span>Orden de compra</span><em class="badge badge-neutral">Abierta</em></div>
                </div>
            </div>
        </div>

        <span class="mock-fab"><x-site.icon name="bot" :size="18" /></span>
    </div>

    <div class="mock-toast is-first">
        <span class="mock-toast-icon is-success"><x-site.icon name="badge-check" /></span>
        <span><b>Hacienda aceptó la factura</b><small>FE-00045 · ₡95.000,00</small></span>
    </div>
    <div class="mock-toast is-second">
        <span class="mock-toast-icon"><x-site.icon name="refresh-cw" /></span>
        <span><b>Tipo de cambio al día</b><small>Sincronizado con el BCCR</small></span>
    </div>
</div>
