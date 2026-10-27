{{--
    El cuerpo del correo del comprobante. Corto: lo que vale son los adjuntos
    (el XML firmado, la respuesta de Hacienda y el PDF).
--}}
<x-mail::message>
# {{ $type }} {{ $consecutive }}

Buen día{{ $customerName ? ', '.$customerName : '' }}:

@if ($test)
**Este es un comprobante del ambiente de pruebas de Hacienda: no tiene valor fiscal.**

@endif
Adjunto va el comprobante electrónico que le emitió **{{ $companyName }}**, aceptado por el Ministerio de Hacienda.

Total: **{{ $currency }} {{ $total }}**

Clave numérica: {{ $clave }}

Van tres archivos: el comprobante en XML firmado (el documento con validez fiscal), la respuesta de Hacienda que lo
acepta, y la representación en PDF.

<x-slot:subcopy>
Este mensaje se generó automáticamente desde el sistema de facturación de {{ $companyName }}. Para cualquier consulta,
responda a este correo.
</x-slot:subcopy>
</x-mail::message>
