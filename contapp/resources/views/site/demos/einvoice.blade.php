{{-- El camino de un comprobante electrónico (CLAUDE.md secc. 38): firmado,
     enviado, en proceso, aceptado y entregado al cliente. --}}
@php
    $steps = [
        ['signature', 'Firmado', 'XAdES-EPES, con la llave de la compañía'],
        ['send', 'Enviado a Hacienda', 'Recepción del comprobante'],
        ['hourglass', 'En proceso', 'Pivox consulta el estado solo'],
        ['badge-check', 'Aceptado', 'La respuesta de Hacienda queda guardada'],
        ['mail', 'Enviado al cliente', 'PDF, XML firmado y respuesta'],
    ];
@endphp

<div class="demo einvoice-demo" data-demo="einvoice" aria-hidden="true">
    <div class="ei-card">
        <div class="ei-head">
            <span class="ei-type"><x-site.icon name="file-text" /> 01 · Factura electrónica</span>
            <span class="ei-status">
                <em class="is-signed">Firmado</em>
                <em class="is-sending">Enviando</em>
                <em class="is-processing">En proceso</em>
                <em class="is-accepted">Aceptado</em>
            </span>
        </div>

        <div class="ei-meta">
            <span><small>Consecutivo</small><b>00100001010000000045</b></span>
            <span><small>Cliente</small><b>Hotel Arenal Vista S.A.</b></span>
            <span><small>Total</small><b>₡95.000,00</b></span>
        </div>

        <ol class="ei-steps">
            @foreach ($steps as $index => [$icon, $title, $detail])
                <li class="ei-step" style="--i: {{ $index }}">
                    <span class="ei-dot"><x-site.icon :name="$icon" /></span>
                    <span class="ei-text"><b>{{ $title }}</b><small>{{ $detail }}</small></span>
                </li>
            @endforeach
        </ol>
    </div>
</div>
