{{--
    La llave criptográfica de Hacienda de una compañía está por vencer
    (pivox:hacienda-certificates). Lo recibe su Superusuario, que es el único
    que la puede reemplazar.
--}}
<x-mail::message>
# La llave de Hacienda vence pronto

Hola{{ $recipientName ? ', '.$recipientName : '' }}:

La llave criptográfica con que **{{ $companyName }}** firma sus comprobantes electrónicos ({{ $environmentLabel }}) vence el
**{{ $expiresOn }}**, dentro de {{ $days }} {{ $days === 1 ? 'día' : 'días' }}.

Con la llave vencida no se pueden firmar comprobantes, y sin firma no se puede facturar. Generá una nueva en el ATV de
Hacienda y cargala en Pivox, en **Administración → Facturación electrónica**.
</x-mail::message>
