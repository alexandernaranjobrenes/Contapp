{{--
    La invitación a aceptar una licencia asignada desde el backoffice. Dice
    qué licencia es, qué va a pedir el enlace (los datos de la compañía y,
    si corresponde, una contraseña) y cuánto dura.
--}}
<x-mail::message>
# Tu licencia de {{ config('app.name') }}

Hola{{ $name ? ', '.$name : '' }}:

El equipo de {{ config('app.name') }} te asignó una licencia{{ $category ? ' '.$category : '' }}, para
llevar la contabilidad de hasta **{{ $maxCompanies }} {{ $maxCompanies === 1 ? 'compañía' : 'compañías' }}**{{ $expiresAt ? ', vigente hasta el '.$expiresAt : '' }}.

Para activarla, aceptala desde este enlace:

<x-mail::button :url="$url">
Aceptar la licencia
</x-mail::button>

@if ($newAccount)
Al aceptarla se crea tu cuenta con el correo **{{ $email }}**: vas a elegir tu contraseña y cargar los datos de tu primera compañía.
@elseif ($requiresPassword)
Se activa en tu cuenta **{{ $email }}**. Como tu contraseña actual la definió otra persona, al aceptar vas a elegir una nueva, y cargar los datos de tu primera compañía.
@else
Se activa en tu cuenta **{{ $email }}**: al aceptar vas a cargar los datos de tu primera compañía.
@endif

El enlace vence en **{{ $minutes }} minutos**. Si vence, pedile al equipo de {{ config('app.name') }} que te lo reenvíe: la licencia no se activa hasta que la aceptes.

<x-slot:subcopy>
Si el botón no funciona, copiá esta dirección y pegala en tu navegador:
<span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
