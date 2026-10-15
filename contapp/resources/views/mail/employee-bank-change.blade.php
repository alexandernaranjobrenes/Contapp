{{--
    Alguien pidió cambiar la cuenta bancaria de un empleado y espera la
    aprobación del Superusuario (EmployeeBankChangeService). Las cuentas van
    enmascaradas.
--}}
<x-mail::message>
# Cambio de cuenta bancaria pendiente

Hola{{ $name ? ', '.$name : '' }}:

**{{ $requestedBy }}** pidió cambiar la cuenta bancaria de **{{ $employee }}** en {{ $company }}:

<x-mail::panel>
Cuenta actual: **{{ $current }}**<br>
Cuenta pedida: **{{ $requested }}**
</x-mail::panel>

Mientras no lo apruebes, la planilla y el archivo de pago del banco siguen usando la cuenta actual.
Si no reconocés este cambio, rechazalo: cambiar la cuenta de un empleado antes de pagar es una forma común de desviar un salario.

<x-mail::button :url="$url">
Revisar el cambio
</x-mail::button>
</x-mail::message>
