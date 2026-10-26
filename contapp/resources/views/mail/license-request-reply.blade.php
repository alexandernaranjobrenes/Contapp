{{--
    La respuesta del equipo de Pivox a una solicitud de licencia: el texto que
    escribió el backoffice y, si lo eligió, el resumen de la cotización.
--}}
<x-mail::message>
@foreach ($paragraphs as $paragraph)
{!! nl2br(e($paragraph)) !!}

@endforeach
@if ($includeQuote)
<x-mail::panel>
**Tu solicitud {{ $request->reference() }}:** {{ $request->package_name ?? 'plan personalizado' }}, {{ $cycle }}.
</x-mail::panel>

<x-mail::table>
| Función | Monto |
|:--------|------:|
@foreach ($lines as $line)
| {{ $line['name'] }} | {{ $line['amount'] }} |
@endforeach
@foreach ($totals as $row)
| **{{ $row['label'] }}** | **{{ $row['amount'] }}** |
@endforeach
</x-mail::table>
@endif

<x-slot:subcopy>
Recibís este correo porque pediste una licencia de {{ config('app.name') }} en pivoxcr.com.@if ($contact) Para escribirnos, respondé este correo o escribí a {{ $contact }}.@endif
</x-slot:subcopy>
</x-mail::message>
