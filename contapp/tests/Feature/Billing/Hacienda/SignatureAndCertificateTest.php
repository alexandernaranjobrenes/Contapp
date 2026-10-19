<?php

use App\Domains\Billing\Exceptions\CertificateException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Services\Hacienda\CertificateInspector;
use App\Domains\Billing\Services\Hacienda\XmlSignatureVerifier;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
| La firma XAdES-EPES y la llave criptográfica.
|
| La firma se verifica con XmlSignatureVerifier, que vuelve a leer el XML
| serializado y recalcula cada digest: si el firmador y Hacienda no
| canonicalizaran igual, esto lo mostraría antes de enviar.
*/

beforeEach(function () {
    Queue::fake();
});

function signedInvoice(): ElectronicDocument
{
    return electronicOf(electronicSale(enableHacienda(salesFixture())));
}

it('la firma verifica de forma independiente: los tres digests y el SignatureValue', function () {
    $electronic = signedInvoice();

    expect(app(XmlSignatureVerifier::class)->problems($electronic->signed_xml))->toBe([]);
});

it('es XAdES-EPES: lleva la política de Hacienda con su hash, no una política implícita (L17)', function () {
    $xml = signedInvoice()->signed_xml;

    expect(haciendaValue($xml, '//xades:SignaturePolicyId/xades:SigPolicyId/xades:Identifier'))->toBe(config('hacienda.signature_policy.url'))
        ->and(haciendaValue($xml, '//xades:SigPolicyHash/ds:DigestValue'))->toBe('DWxin1xWOeI8OuWQXazh4VjLWAaCLAA954em7DMh0h8=')
        ->and(haciendaXpath($xml)->query('//xades:SignaturePolicyImplied')->length)->toBe(0);
});

it('firma el documento, el certificado y las propiedades firmadas, con RSA-SHA256', function () {
    $xml = signedInvoice()->signed_xml;
    $xpath = haciendaXpath($xml);

    $uris = array_map(fn ($node) => $node->getAttribute('URI'), iterator_to_array($xpath->query('//ds:SignedInfo/ds:Reference')));
    $types = array_map(fn ($node) => $node->getAttribute('Type'), iterator_to_array($xpath->query('//ds:SignedInfo/ds:Reference')));

    expect($uris[0])->toBe('')
        ->and($uris[1])->toEndWith('-keyinfo')
        ->and($uris[2])->toEndWith('-signedprops')
        ->and($types[2])->toBe('http://uri.etsi.org/01903#SignedProperties')
        ->and($xpath->query('//ds:SignatureMethod')->item(0)->getAttribute('Algorithm'))->toBe('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256')
        ->and($xpath->query('//ds:Reference[1]/ds:Transforms/ds:Transform')->item(0)->getAttribute('Algorithm'))->toBe('http://www.w3.org/2000/09/xmldsig#enveloped-signature')
        ->and(haciendaValue($xml, '//xades:SignerRole/xades:ClaimedRoles/xades:ClaimedRole'))->toBe('Emisor')
        ->and(haciendaValue($xml, '//xades:SigningTime'))->toEndWith('-06:00');
});

it('cualquier cambio después de firmar rompe la firma: un monto, o el certificado', function () {
    $xml = signedInvoice()->signed_xml;

    $tamperedAmount = str_replace('<TotalComprobante>28250.00000</TotalComprobante>', '<TotalComprobante>1.00000</TotalComprobante>', $xml);
    $tamperedProperties = preg_replace('/<xades:ClaimedRole>Emisor</', '<xades:ClaimedRole>Otro<', $xml);

    expect($tamperedAmount)->not->toBe($xml)
        ->and(app(XmlSignatureVerifier::class)->isValid($tamperedAmount))->toBeFalse()
        ->and(app(XmlSignatureVerifier::class)->isValid($tamperedProperties))->toBeFalse();
});

it('el XML firmado se guarda tal cual, con su SHA-256, y no se reformatea', function () {
    $electronic = signedInvoice();

    expect($electronic->signed_xml_sha256)->toBe(hash('sha256', $electronic->signed_xml))
        ->and($electronic->signed_xml)->not->toContain("\n  <");
});

// --- La llave criptográfica: se revisa al cargarla, no al facturar ---

it('lee los datos públicos de la llave y la cédula del titular', function () {
    $result = app(CertificateInspector::class)->inspect(haciendaP12(), '1234', '3-101-123456');

    expect($result['metadata']['certificate_holder_id'])->toBe('3101123456')
        ->and($result['metadata']['certificate_subject'])->toContain('CN=EMPRESA DE PRUEBA SOCIEDAD ANONIMA')
        ->and($result['metadata']['certificate_fingerprint'])->toHaveLength(64)
        ->and($result['metadata']['certificate_valid_to'] > now())->toBeTrue()
        ->and($result['warnings'])->toBe([]);
});

it('rechaza un PIN que no corresponde', function () {
    app(CertificateInspector::class)->inspect(haciendaP12(), '9999', '3101123456');
})->throws(CertificateException::class, 'El PIN no corresponde');

it('rechaza la llave de otro contribuyente', function () {
    app(CertificateInspector::class)->inspect(haciendaP12('CPJ-3-101-999999'), '1234', '3101123456');
})->throws(CertificateException::class, 'Cada compañía firma con su propia llave');

it('rechaza una llave vencida', function () {
    app(CertificateInspector::class)->inspect(haciendaP12(days: 30), '1234', '3101123456', now()->addDays(60));
})->throws(CertificateException::class, 'venció');

it('rechaza un archivo que no es una llave', function () {
    app(CertificateInspector::class)->inspect('esto no es un p12', '1234');
})->throws(CertificateException::class);

it('con la llave vencida no se emite: la venta no queda registrada', function () {
    $f = enableHacienda(salesFixture());
    $f['credential']->forceFill(['certificate_valid_to' => now()->subDay()])->save();

    expect(fn () => electronicSale($f))->toThrow(App\Domains\Billing\Exceptions\ElectronicInvoicingException::class, 'venció');

    expect(App\Domains\Billing\Models\SalesDocument::where('company_id', $f['company']->id)->count())->toBe(0);
});
