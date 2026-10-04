{{--
    El backoffice quitó una publicación o un comentario del canal de
    comentarios: qué se quitó y por qué.
--}}
<x-mail::message>
# Quitamos {{ $what }}

Hola{{ $name ? ', '.$name : '' }}:

El equipo de {{ config('app.name') }} quitó {{ $what }} del canal de comentarios de la aplicación.

**Motivo:** {{ $reason }}

Lo que habías escrito:

<x-mail::panel>
{{ $excerpt }}
</x-mail::panel>

Podés volver a escribirnos cuando quieras desde el botón de comentarios de la barra superior.
</x-mail::message>
