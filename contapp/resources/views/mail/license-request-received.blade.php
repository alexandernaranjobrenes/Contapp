{{--
    El aviso al backoffice de una solicitud de licencia nueva desde /precios:
    quién la pide, el plan con su cotización y el enlace a Solicitudes.
--}}
<x-mail::message>
# Nueva solicitud de licencia

**{{ $request->reference() }}** · {{ $request->created_at->setTimezone('America/Costa_Rica')->format('d/m/Y H:i') }}

- **Contacto:** {{ $request->contact_name }}
- **Compañía:** {{ $request->company_name }}{{ $request->identification ? ' (cédula '.$request->identification.')' : '' }}
- **Correo:** {{ $request->email }}
@if ($request->phone)
- **Teléfono:** {{ $request->phone }}
@endif
- **Plan:** {{ $request->package_name ?? 'Personalizado' }}, {{ $cycle }}, en {{ $request->currency === 'USD' ? 'dólares' : 'colones' }}

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

@if ($request->message)
**Su mensaje:**

<x-mail::panel>
{{ $request->message }}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
Ver la solicitud
</x-mail::button>

Desde el backoffice decidís si se le responde por correo o se descarta. Responder a este correo le escribe directamente a {{ $request->contact_name }}.
</x-mail::message>
