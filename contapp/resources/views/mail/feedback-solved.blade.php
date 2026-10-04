{{--
    El agradecimiento por una publicación del canal de comentarios que el
    backoffice dio por resuelta, con el mensaje del equipo si lo escribió.
--}}
<x-mail::message>
# ¡Gracias por tu aporte!

Hola{{ $name ? ', '.$name : '' }}:

Tu publicación en el canal de comentarios de {{ config('app.name') }} quedó resuelta:

<x-mail::panel>
{{ $excerpt }}
</x-mail::panel>

@if ($note)
**Mensaje del equipo:** {{ $note }}

@endif
Gracias por tomarte el tiempo de escribirnos: lo que nos cuentan es lo que hace mejor a {{ config('app.name') }}.
Como ya está resuelta, la publicación deja de verse en la aplicación.
</x-mail::message>
