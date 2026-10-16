{{--
    El equipo de CONTAPP exportó un respaldo de la compañía (CLAUDE.md secc.
    37): el Superusuario se entera en el momento.
--}}
<x-mail::message>
# Se exportó un respaldo de {{ $company }}

Hola{{ $name ? ', '.$name : '' }}:

El equipo de CONTAPP (**{{ $by }}**) exportó un respaldo de **{{ $company }}** el {{ $at }}.

<x-mail::panel>
Período: **{{ $period }}**
</x-mail::panel>

El archivo va cifrado con una contraseña. Si no esperabas este respaldo, respondé este correo o escribinos a servicios@ncodedigital.com.
</x-mail::message>
