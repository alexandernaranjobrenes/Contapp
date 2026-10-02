{{--
    Confirma un cambio de correo. Lo recibe la casilla nueva: hasta que se
    abre el enlace, la cuenta sigue con el correo anterior.
--}}
<x-mail::message>
# Confirmá tu correo nuevo

Hola{{ $name ? ', '.$name : '' }}:

Pediste usar esta dirección como correo de tu cuenta de {{ config('app.name') }}.
Tocá el botón para confirmarla.

<x-mail::button :url="$url">
Confirmar este correo
</x-mail::button>

El enlace vence en **{{ $minutes }} minutos**. Hasta que lo abras, seguís
entrando con tu correo anterior.

Si no fuiste vos, no hagas nada: sin abrir el enlace, nada cambia.

<x-slot:subcopy>
Si el botón no funciona, copiá esta dirección y pegala en tu navegador:
<span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
