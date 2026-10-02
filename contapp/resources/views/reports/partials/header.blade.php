@inject('media', 'App\Domains\Core\Support\MediaStorage')
@php
    // Incrustado: dompdf no baja imágenes por HTTP. Y medido: el logo se
    // dibuja con su ancho y alto ya calculados para la caja del encabezado
    // (ReportLogo), sea apaisado, cuadrado o vertical.
    $logo = $media->dataUri($header->logoPath);
    $logoSize = \App\Domains\Reporting\Support\ReportLogo::size($logo);
@endphp
<div style="border-bottom: 2px solid #0B1F3A; padding-bottom: 8px; margin-bottom: 12px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            {{-- La celda del logo existe solo si hay logo. Sin él, el nombre
                 arranca en el margen, alineado con el título: una celda
                 vacía dejaba un hueco que parecía un logo que no cargó. --}}
            @if ($logo)
                <td style="width: {{ \App\Domains\Reporting\Support\ReportLogo::cellWidth($logoSize) }}px; vertical-align: middle;">
                    <img src="{{ $logo }}" style="{{ \App\Domains\Reporting\Support\ReportLogo::style($logoSize) }}">
                </td>
            @endif
            <td style="vertical-align: top;">
                <div style="font-size: 14px; font-weight: bold; color: #0B1F3A;">{{ $header->companyName }}</div>
                @if ($header->taxId)
                    <div style="font-size: 9px; color: #555;">Cédula jurídica: {{ $header->taxId }}</div>
                @endif
                @if ($header->address)
                    <div style="font-size: 9px; color: #555;">{{ $header->address }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 10px; font-size: 13px; font-weight: bold;">{{ $header->title }}</div>
    <div style="font-size: 9px; color: #555;">{{ $header->paramsSummary }}</div>
</div>
