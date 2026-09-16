<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Exceptions\HaciendaNotConfiguredException;
use App\Domains\Billing\Models\BillingPaymentAccount;
use App\Domains\Billing\Services\SalesDocumentXmlBuilder;

function buildXml(array $f, array $overrides = []): string
{
    $document = postSale($f, $overrides);

    return app(SalesDocumentXmlBuilder::class)->build($document, $f['company']);
}

function xpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);

    $path = new DOMXPath($dom);
    $path->registerNamespace('fe', $dom->documentElement->namespaceURI);

    return $path;
}

function xmlValue(string $xml, string $expression): ?string
{
    $nodes = xpath($xml)->query($expression);

    return $nodes->length ? $nodes->item(0)->nodeValue : null;
}

it('genera un XML bien formado con el nodo raíz del tipo de comprobante', function () {
    $f = salesFixture();
    $xml = buildXml($f);

    $dom = new DOMDocument;

    expect($dom->loadXML($xml))->toBeTrue()
        ->and($dom->documentElement->localName)->toBe('FacturaElectronica')
        ->and($dom->documentElement->namespaceURI)->toContain('v4.4/facturaElectronica');
});

it('el nodo raíz cambia según el tipo de comprobante', function () {
    $f = salesFixture();

    $xml = buildXml($f, [
        'fiscalType' => '03',
        'references' => [[
            'document_type' => '01',
            'number' => str_repeat('1', 50),
            'reason_code' => '01',
            'reason' => 'Anula la factura original',
        ]],
    ]);

    $dom = new DOMDocument;
    $dom->loadXML($xml);

    expect($dom->documentElement->localName)->toBe('NotaCreditoElectronica');
});

it('lleva la clave, el consecutivo y la actividad económica del emisor', function () {
    $f = salesFixture();
    $document = postSale($f);
    $xml = app(SalesDocumentXmlBuilder::class)->build($document, $f['company']);

    expect(xmlValue($xml, '//fe:Clave'))->toBe($document->clave)
        ->and(xmlValue($xml, '//fe:NumeroConsecutivo'))->toBe($document->consecutive)
        ->and(xmlValue($xml, '//fe:CodigoActividadEmisor'))->toBe('620100');
});

it('el resumen lleva los totales con cinco decimales', function () {
    $f = salesFixture();
    $xml = buildXml($f);

    expect(xmlValue($xml, '//fe:ResumenFactura/fe:TotalVentaNeta'))->toBe('25000.00000')
        ->and(xmlValue($xml, '//fe:ResumenFactura/fe:TotalImpuesto'))->toBe('3250.00000')
        ->and(xmlValue($xml, '//fe:ResumenFactura/fe:TotalComprobante'))->toBe('28250.00000');
});

it('la línea lleva su CAByS, unidad e impuesto', function () {
    $f = salesFixture();
    $xml = buildXml($f);

    expect(xmlValue($xml, '//fe:LineaDetalle/fe:CodigoCABYS'))->toBe('2310110000000')
        ->and(xmlValue($xml, '//fe:LineaDetalle/fe:UnidadMedida'))->toBe('Unid')
        ->and(xmlValue($xml, '//fe:LineaDetalle/fe:Impuesto/fe:CodigoTarifaIVA'))->toBe('08')
        ->and(xmlValue($xml, '//fe:LineaDetalle/fe:Impuesto/fe:Tarifa'))->toBe('13.00');
});

it('una línea exonerada incluye el sub-nodo de exoneración', function () {
    $f = salesFixture();

    $xml = buildXml($f, ['lines' => [
        saleLine($f, 10, 1000, ['taxes' => [new SalesTaxInput(
            ivaRateCode: '08',
            exonerationDocumentType: '08',
            exonerationDocumentNumber: 'ZF-2026-001',
            exonerationInstitution: '01',
            exoneratedPercentage: 100,
        )]]),
    ]]);

    expect(xmlValue($xml, '//fe:Exoneracion/fe:TipoDocumentoEX'))->toBe('08')
        ->and(xmlValue($xml, '//fe:Exoneracion/fe:NumeroDocumento'))->toBe('ZF-2026-001')
        ->and(xmlValue($xml, '//fe:Exoneracion/fe:MontoExoneracion'))->toBe('1300.00000');
});

it('los medios de pago van dentro del resumen, uno por nodo', function () {
    $f = salesFixture();

    $efectivo = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    BillingPaymentAccount::create([
        'company_id' => $f['company']->id, 'method_code' => '06', 'account_id' => $efectivo->id,
    ]);

    $xml = buildXml($f, [
        'condition' => '01',
        'creditTermDays' => null,
        'payments' => [['method_code' => '06', 'amount' => 28250]],
    ]);

    expect(xmlValue($xml, '//fe:ResumenFactura/fe:MedioPago/fe:TipoMedioPago'))->toBe('06')
        ->and(xmlValue($xml, '//fe:ResumenFactura/fe:MedioPago/fe:TotalMedioPago'))->toBe('28250.00000');
});

it('escapa caracteres que romperían un XML armado a mano', function () {
    $f = salesFixture();
    $f['customer']->update(['name' => 'Ferretería & Cía <SA>']);

    $xml = buildXml($f);

    $dom = new DOMDocument;

    expect($dom->loadXML($xml))->toBeTrue()
        ->and(xmlValue($xml, '//fe:Receptor/fe:Nombre'))->toBe('Ferretería & Cía <SA>');
});

it('un tiquete electrónico a consumidor final no lleva receptor', function () {
    $f = salesFixture();

    $efectivo = ChartOfAccount::factory()->create(['company_id' => $f['company']->id]);
    BillingPaymentAccount::create([
        'company_id' => $f['company']->id, 'method_code' => '01', 'account_id' => $efectivo->id,
    ]);

    $xml = buildXml($f, [
        'fiscalType' => '04',
        'partnerId' => null,
        'condition' => '01',
        'creditTermDays' => null,
        'payments' => [['method_code' => '01', 'amount' => 28250]],
    ]);

    expect(xpath($xml)->query('//fe:Receptor')->length)->toBe(0);
});

it('la nota de crédito arrastra su documento de referencia', function () {
    $f = salesFixture();

    $xml = buildXml($f, [
        'fiscalType' => '03',
        'references' => [[
            'document_type' => '01',
            'number' => str_repeat('9', 50),
            'reason_code' => '02',
            'reason' => 'Corrige monto facturado de más',
        ]],
    ]);

    expect(xmlValue($xml, '//fe:InformacionReferencia/fe:TipoDocIR'))->toBe('01')
        ->and(xmlValue($xml, '//fe:InformacionReferencia/fe:Codigo'))->toBe('02')
        ->and(xmlValue($xml, '//fe:InformacionReferencia/fe:Razon'))->toBe('Corrige monto facturado de más');
});

// --- Firma y envío ---

it('la firma falla explícitamente mientras no haya certificado configurado', function () {
    app(HaciendaSigner::class)->sign('<xml/>');
})->throws(HaciendaNotConfiguredException::class);

it('el envío falla explícitamente mientras no haya credenciales del ATV', function () {
    $f = salesFixture();

    app(HaciendaTransport::class)->send(postSale($f), '<xml/>');
})->throws(HaciendaNotConfiguredException::class);

it('firma y envío se declaran no configurados, para que la UI pueda avisarlo', function () {
    expect(app(HaciendaSigner::class)->isConfigured())->toBeFalse()
        ->and(app(HaciendaTransport::class)->isConfigured())->toBeFalse();
});
