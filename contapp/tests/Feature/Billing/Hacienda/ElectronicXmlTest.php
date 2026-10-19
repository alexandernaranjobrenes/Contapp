<?php

use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Support\HaciendaClock;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
| Cada escenario de la matriz de certificación (guía, secc. 7.4) que se
| resuelve en el XML, emitido de punta a punta: la venta, el XML, el XSD
| oficial y la firma. El envío a Hacienda va aparte (HaciendaSubmissionTest).
|
| Y cada rechazo real que costó la implementación anterior (guía, secc. 3.9,
| L1 a L16) como una prueba de que el XML ya no lo repite.
*/

beforeEach(function () {
    Queue::fake();
    $this->f = enableHacienda(salesFixture());
});

// --- Matriz de escenarios: todos pasan el XSD oficial, firma incluida ---

it('1 · factura en colones, gravada al 13 %, a crédito', function () {
    $electronic = electronicOf(electronicSale($this->f));

    expectValidAgainstXsd($electronic);
    expect($electronic->status)->toBe(ElectronicDocument::SIGNED)
        ->and(haciendaValue($electronic->signed_xml, '//fe:PlazoCredito'))->toBe('30');
});

it('2 · una línea exenta lleva su Impuesto con la tarifa 10 y va al total exento', function () {
    $document = electronicSale($this->f, ['lines' => [
        saleLine($this->f, 1, 5000, ['isService' => true, 'itemId' => null, 'warehouseId' => null, 'taxes' => [new SalesTaxInput(ivaRateCode: '10')]]),
    ]]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:LineaDetalle/fe:Impuesto/fe:CodigoTarifaIVA'))->toBe('10')
        ->and(haciendaValue($xml, '//fe:TotalServExentos'))->toBe('5000.00000')
        ->and(haciendaValue($xml, '//fe:TotalExento'))->toBe('5000.00000')
        ->and(haciendaValue($xml, '//fe:TotalGravado'))->toBe('0.00000');
});

it('3 · una línea no sujeta va a su propio total, no al exento (L10)', function () {
    $document = electronicSale($this->f, ['lines' => [
        saleLine($this->f, 1, 4000, ['isService' => true, 'itemId' => null, 'warehouseId' => null, 'taxes' => [new SalesTaxInput(ivaRateCode: '11')]]),
    ]]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:TotalServNoSujeto'))->toBe('4000.00000')
        ->and(haciendaValue($xml, '//fe:TotalNoSujeto'))->toBe('4000.00000')
        ->and(haciendaValue($xml, '//fe:TotalExento'))->toBe('0.00000');
});

it('4 · servicios y mercancías, gravados y exentos, cada uno en su total', function () {
    $document = electronicSale($this->f, ['lines' => [
        saleLine($this->f, 10, 1000),
        saleLine($this->f, 1, 5000, ['isService' => true, 'itemId' => null, 'warehouseId' => null]),
        saleLine($this->f, 1, 3000, ['isService' => true, 'itemId' => null, 'warehouseId' => null, 'taxes' => [new SalesTaxInput(ivaRateCode: '10')]]),
    ]]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:TotalMercanciasGravadas'))->toBe('10000.00000')
        ->and(haciendaValue($xml, '//fe:TotalServGravados'))->toBe('5000.00000')
        ->and(haciendaValue($xml, '//fe:TotalServExentos'))->toBe('3000.00000')
        ->and(haciendaValue($xml, '//fe:TotalVenta'))->toBe('18000.00000');
});

it('5 · un descuento lleva su código y su naturaleza, y los totales se clasifican sobre el monto bruto', function () {
    $document = electronicSale($this->f, ['lines' => [
        saleLine($this->f, 10, 2500, ['discountCode' => '07', 'discountAmount' => 2500]),
    ]]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:Descuento/fe:CodigoDescuento'))->toBe('07')
        ->and(haciendaValue($xml, '//fe:Descuento/fe:NaturalezaDescuento'))->toBe('Descuento comercial')
        // TotalVenta = gravado + exento + exonerado + no sujeto, sobre el
        // bruto; el descuento se resta una sola vez, en TotalVentaNeta.
        ->and(haciendaValue($xml, '//fe:TotalMercanciasGravadas'))->toBe('25000.00000')
        ->and(haciendaValue($xml, '//fe:TotalVenta'))->toBe('25000.00000')
        ->and(haciendaValue($xml, '//fe:TotalDescuentos'))->toBe('2500.00000')
        ->and(haciendaValue($xml, '//fe:TotalVentaNeta'))->toBe('22500.00000')
        ->and(haciendaValue($xml, '//fe:TotalImpuesto'))->toBe('2925.00000')
        ->and(haciendaValue($xml, '//fe:TotalComprobante'))->toBe('25425.00000');
});

it('6 · en dólares lleva su código de moneda y el tipo de cambio', function () {
    $document = electronicSale($this->f, [
        'currencyId' => $this->f['company']->foreign_currency_id,
        'exchangeRate' => '505.25',
    ]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:CodigoTipoMoneda/fe:CodigoMoneda'))->toBe($this->f['company']->foreignCurrency->code)
        ->and(haciendaValue($xml, '//fe:CodigoTipoMoneda/fe:TipoCambio'))->toBe('505.25000');
});

it('8 · dos medios de pago, cada uno con su monto real', function () {
    $document = cashSale($this->f, [
        ['method_code' => '01', 'amount' => 8250],
        ['method_code' => '06', 'amount' => 20000],
    ]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaXpath($xml)->query('//fe:MedioPago')->length)->toBe(2)
        ->and(haciendaValue($xml, '//fe:MedioPago[1]/fe:TotalMedioPago'))->toBe('8250.00000')
        ->and(haciendaValue($xml, '//fe:MedioPago[2]/fe:TotalMedioPago'))->toBe('20000.00000');
});

it('9 · el medio de pago «Otros» lleva su descripción', function () {
    $document = cashSale($this->f, [['method_code' => '99', 'amount' => 28250, 'method_other' => 'Canje de puntos']]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:MedioPago/fe:MedioPagoOtros'))->toBe('Canje de puntos');
});

it('10 · la condición de venta «Otros» lleva su descripción', function () {
    $document = cashSale($this->f, [['method_code' => '01', 'amount' => 28250]], [
        'condition' => '99',
        'conditionOther' => 'Pago contra entrega del proyecto',
    ]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:CondicionVenta'))->toBe('99')
        ->and(haciendaValue($xml, '//fe:CondicionVentaOtros'))->toBe('Pago contra entrega del proyecto');
});

it('12 · el emisor lleva su ubicación con barrio, su nombre comercial, teléfono y correo', function () {
    $xml = electronicOf(electronicSale($this->f))->signed_xml;

    expect(haciendaValue($xml, '//fe:Emisor/fe:NombreComercial'))->toBe('Empresa de Prueba')
        ->and(haciendaValue($xml, '//fe:Emisor/fe:Ubicacion/fe:Provincia'))->toBe('1')
        ->and(haciendaValue($xml, '//fe:Emisor/fe:Ubicacion/fe:Distrito'))->toBe('08')
        ->and(haciendaValue($xml, '//fe:Emisor/fe:Ubicacion/fe:Barrio'))->toBe('Barrio Amón')
        ->and(haciendaValue($xml, '//fe:Emisor/fe:Telefono/fe:NumTelefono'))->toBe('22223333')
        ->and(haciendaValue($xml, '//fe:Emisor/fe:CorreoElectronico'))->toBe('facturacion@empresa.cr')
        ->and(haciendaValue($xml, '//fe:Receptor/fe:Identificacion/fe:Numero'))->toBe('3101654321')
        ->and(haciendaValue($xml, '//fe:Receptor/fe:Telefono/fe:NumTelefono'))->toBe('88887777');
});

it('15 · una exoneración parcial va con su tarifa exonerada y reparte la línea entre gravado y exonerado', function () {
    $document = electronicSale($this->f, ['lines' => [
        saleLine($this->f, 10, 1000, ['taxes' => [new SalesTaxInput(
            ivaRateCode: '08',
            exonerationDocumentType: '03',
            exonerationDocumentNumber: 'AL-00012345-24',
            exonerationArticle: '11',
            exonerationInstitution: '01',
            exonerationDate: '2026-01-15',
            exoneratedPercentage: 50,
        )]]),
    ]]);

    $xml = electronicOf($document)->signed_xml;

    expectValidAgainstXsd(electronicOf($document));
    expect(haciendaValue($xml, '//fe:Exoneracion/fe:TipoDocumentoEX1'))->toBe('03')
        ->and(haciendaValue($xml, '//fe:Exoneracion/fe:TarifaExonerada'))->toBe('6.50')
        ->and(haciendaValue($xml, '//fe:Exoneracion/fe:MontoExoneracion'))->toBe('650.00000')
        ->and(haciendaValue($xml, '//fe:Exoneracion/fe:FechaEmisionEX'))->toBe('2026-01-15T00:00:00-06:00')
        ->and(haciendaValue($xml, '//fe:LineaDetalle/fe:ImpuestoNeto'))->toBe('650.00000')
        ->and(haciendaValue($xml, '//fe:TotalMercExonerada'))->toBe('5000.00000')
        ->and(haciendaValue($xml, '//fe:TotalMercanciasGravadas'))->toBe('5000.00000')
        ->and(haciendaValue($xml, '//fe:TotalImpuesto'))->toBe('650.00000');
});

it('16 · la nota de crédito usa su propio namespace y referencia la factura con su fecha exacta', function () {
    $invoice = electronicSale($this->f);
    electronicOf($invoice)->update(['status' => ElectronicDocument::ACCEPTED]);

    $note = electronicSale($this->f, [
        'fiscalType' => '03',
        'originalId' => $invoice->id,
        'references' => [[
            'document_type' => '01',
            'number' => $invoice->clave,
            'reason_code' => '01',
            'reason' => 'Anula la factura por devolución total',
        ]],
    ]);

    $electronic = electronicOf($note);
    $dom = new DOMDocument;
    $dom->loadXML($electronic->signed_xml);

    expectValidAgainstXsd($electronic);
    expect($dom->documentElement->localName)->toBe('NotaCreditoElectronica')
        ->and($dom->documentElement->namespaceURI)->toEndWith('/v4.4/notaCreditoElectronica')
        ->and(haciendaValue($electronic->signed_xml, '//fe:InformacionReferencia/fe:Numero'))->toBe($invoice->clave)
        // FechaEmisionIR: la de la factura, con su hora, tomada de su clave.
        ->and(haciendaValue($electronic->signed_xml, '//fe:InformacionReferencia/fe:FechaEmisionIR'))->toBe(HaciendaClock::format($invoice->issued_at));
});

it('18 · la nota de débito valida contra su propio XSD', function () {
    $invoice = electronicSale($this->f);

    $debit = electronicSale($this->f, [
        'fiscalType' => '02',
        'lines' => [saleLine($this->f, 1, 1500, ['isService' => true, 'itemId' => null, 'warehouseId' => null])],
        'references' => [[
            'document_type' => '01',
            'number' => $invoice->clave,
            'reason_code' => '04',
            'reason' => 'Cobro de intereses por atraso',
        ]],
    ]);

    $electronic = electronicOf($debit);

    expectValidAgainstXsd($electronic);
    expect(haciendaXpath($electronic->signed_xml)->query('/fe:NotaDebitoElectronica')->length)->toBe(1);
});

it('19 · el tiquete a consumidor final no lleva receptor', function () {
    $document = cashSale($this->f, [['method_code' => '01', 'amount' => 28250]], ['fiscalType' => '04', 'partnerId' => null]);

    $electronic = electronicOf($document);

    expectValidAgainstXsd($electronic);
    expect(haciendaXpath($electronic->signed_xml)->query('//fe:Receptor')->length)->toBe(0)
        ->and($electronic->receiver_id_type)->toBeNull();
});

it('la factura de exportación va sin base imponible, exoneración ni impuesto neto, como su XSD', function () {
    $this->f['customer']->update(['identification_type' => '05', 'tax_id' => 'US-EIN-987654', 'address_details' => '1200 Brickell Ave, Miami, FL']);

    $document = cashSale($this->f, [['method_code' => '04', 'amount' => 25000]], [
        'fiscalType' => '09',
        'lines' => [saleLine($this->f, 10, 2500, ['taxes' => [new SalesTaxInput(ivaRateCode: '01')]])],
    ]);

    $electronic = electronicOf($document);
    $xml = $electronic->signed_xml;

    expectValidAgainstXsd($electronic);
    expect(haciendaXpath($xml)->query('//fe:BaseImponible')->length)->toBe(0)
        ->and(haciendaXpath($xml)->query('//fe:ImpuestoNeto')->length)->toBe(0)
        ->and(haciendaValue($xml, '//fe:Receptor/fe:OtrasSenasExtranjero'))->toBe('1200 Brickell Ave, Miami, FL');
});

// --- Lecciones aprendidas (guía, secc. 3.9): el XML no las repite ---

it('L1 y L2: ProveedorSistemas va después de la clave, como texto', function () {
    $xml = electronicOf(electronicSale($this->f))->signed_xml;

    // El cliente no tiene actividad económica: CodigoActividadReceptor, que
    // es opcional, no va.
    expect(array_slice(haciendaChildren($xml, '/*'), 0, 5))
        ->toBe(['Clave', 'ProveedorSistemas', 'CodigoActividadEmisor', 'NumeroConsecutivo', 'FechaEmision'])
        ->and(haciendaXpath($xml)->query('//fe:ProveedorSistemas/*')->length)->toBe(0)
        ->and(haciendaValue($xml, '//fe:ProveedorSistemas'))->toBe('3101123456');
});

it('L3: la actividad económica va con el formato 0000.0', function () {
    expect(haciendaValue(electronicOf(electronicSale($this->f))->signed_xml, '//fe:CodigoActividadEmisor'))->toBe('6201.0');
});

it('L5 a L8: la línea va en el orden del XSD, con Impuesto siempre y el impuesto asumido en cero', function () {
    $xml = electronicOf(electronicSale($this->f))->signed_xml;

    expect(haciendaChildren($xml, '//fe:LineaDetalle'))->toBe([
        'NumeroLinea', 'CodigoCABYS', 'CodigoComercial', 'Cantidad', 'UnidadMedida', 'Detalle',
        'PrecioUnitario', 'MontoTotal', 'SubTotal', 'BaseImponible', 'Impuesto',
        'ImpuestoAsumidoEmisorFabrica', 'ImpuestoNeto', 'MontoTotalLinea',
    ])->and(haciendaValue($xml, '//fe:ImpuestoAsumidoEmisorFabrica'))->toBe('0.00000');
});

it('L9, L11 y L12: el resumen lleva TipoCambio en colones, el desglose de impuestos y los medios de pago antes del total', function () {
    $xml = electronicOf(cashSale($this->f, [['method_code' => '01', 'amount' => 28250]]))->signed_xml;
    $summary = haciendaChildren($xml, '//fe:ResumenFactura');

    expect(haciendaValue($xml, '//fe:CodigoTipoMoneda/fe:TipoCambio'))->toBe('1.00000')
        ->and(haciendaValue($xml, '//fe:TotalDesgloseImpuesto/fe:CodigoTarifaIVA'))->toBe('08')
        ->and(haciendaValue($xml, '//fe:TotalDesgloseImpuesto/fe:TotalMontoImpuesto'))->toBe('3250.00000')
        ->and(array_slice($summary, -3))->toBe(['TotalImpuesto', 'MedioPago', 'TotalComprobante']);
});

it('L14: la fecha de la clave es la de FechaEmision, en hora de Costa Rica', function () {
    $document = electronicSale($this->f);
    $xml = electronicOf($document)->signed_xml;

    $issued = new DateTimeImmutable(haciendaValue($xml, '//fe:FechaEmision'));

    expect(substr($document->clave, 3, 6))->toBe($issued->format('dmy'))
        ->and(haciendaValue($xml, '//fe:FechaEmision'))->toEndWith('-06:00');
});

it('el consecutivo de la nota de crédito tiene su propia serie, aparte de la factura', function () {
    $invoice = electronicSale($this->f);
    electronicOf($invoice)->update(['status' => ElectronicDocument::ACCEPTED]);

    $second = electronicSale($this->f);

    $note = electronicSale($this->f, [
        'fiscalType' => '03',
        'originalId' => $invoice->id,
        'references' => [['document_type' => '01', 'number' => $invoice->clave, 'reason_code' => '01', 'reason' => 'Anula la factura']],
    ]);

    expect(substr($invoice->consecutive, -10))->toBe('0000000001')
        ->and(substr($second->consecutive, -10))->toBe('0000000002')
        ->and(substr($note->consecutive, 8, 2))->toBe('03')
        ->and(substr($note->consecutive, -10))->toBe('0000000001');
});
