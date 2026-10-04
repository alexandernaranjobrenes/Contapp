{{--
    La invitación a trabajar en una compañía. Dice quién invita, a qué
    compañía y con qué rol, qué va a pedir el enlace (crear la cuenta, o
    nada si ya la tiene) y hasta cuándo sirve.
--}}
<x-mail::message>
# Te invitaron a {{ $company }}

Hola:

{{ $inviter ?: 'Alguien' }} te invitó a trabajar en **{{ $company }}** en {{ config('app.name') }}, como **{{ $role }}**.

<x-mail::button :url="$url">
Aceptar la invitación
</x-mail::button>

@if ($hasAccount)
Ya tenés una cuenta con el correo **{{ $email }}**: al aceptar, esta compañía se suma a las que ya ves, y entrás con tu contraseña de siempre.
@else
Al aceptar vas a crear tu cuenta con el correo **{{ $email }}**: elegís tu nombre como querés que se vea y tu contraseña, que solo vas a saber vos.
@endif

La invitación vence en **{{ $days }} días**{{ $expiresAt ? ' (el '.$expiresAt.')' : '' }}. Hasta que la aceptes no vas a tener acceso a la compañía. Si no esperabas este correo, ignoralo: sin aceptar, nada cambia.

<x-slot:subcopy>
Si el botón no funciona, copiá esta dirección y pegala en tu navegador:
<span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
