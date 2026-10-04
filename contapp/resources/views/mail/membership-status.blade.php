{{--
    El acceso de una persona a las compañías de una licencia cambió:
    suspendido, devuelto o dado de baja (UserLifecycleService).
--}}
<x-mail::message>
@if ($status === 'suspended')
# Tu acceso quedó suspendido
@elseif ($status === 'active')
# Ya podés volver a entrar
@else
# Se dio de baja tu acceso
@endif

Hola{{ $name ? ', '.$name : '' }}:

@if ($status === 'suspended')
{{ $changedBy ?: 'Quien administra la compañía' }} suspendió tu acceso en {{ config('app.name') }} a:
@elseif ($status === 'active')
{{ $changedBy ?: 'Quien administra la compañía' }} te devolvió el acceso en {{ config('app.name') }} a:
@else
{{ $changedBy ?: 'Quien administra la compañía' }} dio de baja tu acceso en {{ config('app.name') }} a:
@endif

@foreach ($companies as $company)
- **{{ $company }}**
@endforeach

@if ($status === 'suspended')
Mientras dure la suspensión no vas a poder entrar a {{ count($companies) === 1 ? 'esa compañía' : 'esas compañías' }}. Tus permisos quedan guardados tal cual: si te reactivan, volvés a entrar como antes.
@elseif ($status === 'active')
Entrás con tu correo y tu contraseña de siempre, con los mismos permisos que tenías.

<x-mail::button :url="$loginUrl">
Entrar a {{ config('app.name') }}
</x-mail::button>
@else
Ya no vas a poder entrar a {{ count($companies) === 1 ? 'esa compañía' : 'esas compañías' }}. Lo que registraste ahí queda en su historial. Tu cuenta de {{ config('app.name') }} sigue existiendo: si trabajás en otras compañías, ahí nada cambia.
@endif

@if ($status !== 'active')
Si creés que es un error, hablalo con {{ $changedBy ?: 'quien administra la compañía' }}.
@endif
</x-mail::message>
