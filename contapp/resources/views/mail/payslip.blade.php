{{--
    El cuerpo del correo. Deliberadamente corto: el documento es el adjunto.

    Lleva el neto y la fecha de pago porque son los dos datos que alguien
    quiere confirmar sin abrir el PDF; el desglose completo va adentro, que es
    donde puede llevar la base y la tasa de cada rebajo.
--}}
<x-mail::message>
# Comprobante de pago

Buen día{{ $employeeName ? ', '.$employeeName : '' }}:

Adjunto va su comprobante de pago correspondiente a **{{ $periodName }}**.

@if ($paymentDate)
Fecha de pago: **{{ $paymentDate }}**
@endif

Neto pagado: **₡{{ number_format((float) $netPay, 2, ',', '.') }}**

El comprobante adjunto detalla cada ingreso y cada deducción con su base y su
tasa, para que pueda verificarlo. Si encuentra alguna diferencia, comuníquela
al departamento de recursos humanos.

<x-slot:subcopy>
Este mensaje se generó automáticamente desde el sistema de planillas de
{{ $companyName }}.
</x-slot:subcopy>
</x-mail::message>
