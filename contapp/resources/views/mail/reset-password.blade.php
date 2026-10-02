{{--
    El correo de «Olvidé mi contraseña». Corto: un botón y lo que hay que
    saber del enlace (cuánto dura, que sirve una vez, qué hacer si no lo
    pediste).

    La dirección va también escrita al pie porque hay clientes de correo que
    no muestran el botón.
--}}
<x-mail::message>
# Restablecé tu contraseña

Hola{{ $name ? ', '.$name : '' }}:

Recibimos un pedido para cambiar la contraseña de tu cuenta de {{ config('app.name') }}.
Tocá el botón para elegir una nueva.

<x-mail::button :url="$url">
Elegir contraseña nueva
</x-mail::button>

El enlace vence en **{{ $minutes }} minutos** y sirve una sola vez.

Si no fuiste vos, no hagas nada: tu contraseña sigue siendo la misma.

<x-slot:subcopy>
Si el botón no funciona, copiá esta dirección y pegala en tu navegador:
<span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
