{{-- Escanear un documento con Conti (CLAUDE.md secc. 32): la computadora
     muestra el QR, el teléfono fotografía la factura, y el formulario llega a
     la computadora con lo leído marcado «Del documento». --}}
@php
    $fields = [
        ['Proveedor', 'Ferretería El Roble S.A.'],
        ['Cédula', '3-101-456789'],
        ['N.º de factura', '00100001010000004521'],
        ['Fecha', '22/10/2026'],
        ['Subtotal', '₡84.070,80'],
        ['IVA 13 %', '₡10.929,20'],
        ['Total', '₡95.000,00'],
    ];
@endphp

<div class="demo scan-demo" data-demo="scanner" aria-hidden="true">
    <div class="sd-stage">
        <div class="sd-laptop">
            <div class="sd-screen">
                <div class="sd-modal">
                    <b class="sd-title"><x-site.icon name="scan-line" /> Escanear un documento</b>
                    <span class="sd-choice"><x-site.icon name="receipt" /> Factura de gasto o compra <x-site.icon name="check" class="sd-choice-check" /></span>
                    <span class="sd-qr">@include('site.demos.qr')</span>
                    <small>Escaneá el código con la cámara del teléfono</small>
                </div>

                <div class="sd-form">
                    <b class="sd-title"><x-site.icon name="bot" /> Registrar una factura</b>
                    @foreach ($fields as $index => [$label, $value])
                        <div class="sd-field" style="--i: {{ $index }}"><small>{{ $label }}</small><b>{{ $value }}</b><em>Del documento</em></div>
                    @endforeach
                    <p class="sd-note"><x-site.icon name="info" /> Revisá cada dato antes de enviarlo.</p>
                </div>
            </div>
            <div class="sd-base"></div>
        </div>

        <div class="sd-phone">
            <div class="sd-phone-screen">
                <div class="sd-camera">
                    <div class="sd-paper">
                        <b>Factura electrónica</b>
                        <i></i><i></i><i class="is-short"></i>
                        <span class="sd-paper-rows"><i></i><i></i><i></i></span>
                        <span class="sd-paper-total">Total ₡95.000,00</span>
                    </div>
                    <span class="sd-corners"></span>
                    <span class="sd-scanline"></span>
                    <span class="sd-flash"></span>
                </div>
                <div class="sd-shutter"><span></span></div>
                <div class="sd-sent">
                    <x-site.icon name="circle-check" :size="30" />
                    <b>Listo</b>
                    <small>Seguí en la computadora</small>
                </div>
            </div>
        </div>
    </div>
</div>
