<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\Models\BillingPaymentAccount;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\HaciendaCredential;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\Hacienda\CertificateInspector;
use App\Domains\Billing\Services\Hacienda\XsdValidator;
use App\Domains\Billing\Services\PostSalesDocumentService;
use Illuminate\Support\Facades\Http;

/*
| Ayudas de las pruebas de facturación electrónica (CLAUDE.md secc. 38). Cada
| archivo de esta carpeta las incluye.
|
| Ninguna prueba habla con Hacienda: la llave es autofirmada y generada acá,
| y el IDP y el API de recepción se simulan con Http::fake, con
| preventStrayRequests para que una llamada no prevista falle en vez de salir
| a la red.
*/

if (! function_exists('haciendaOpensslConfig')) {
    /**
     * En Windows (XAMPP) OpenSSL no encuentra su openssl.cnf solo, y sin él
     * no genera llaves. En Linux (Docker) no hace falta.
     *
     * @return array<string, string>
     */
    function haciendaOpensslConfig(): array
    {
        $candidate = dirname(PHP_BINARY).'/extras/openssl/openssl.cnf';

        return PHP_OS_FAMILY === 'Windows' && is_file($candidate) ? ['config' => $candidate] : [];
    }

    /**
     * Una llave criptográfica de prueba (.p12), como la del ATV: RSA 2048 y la
     * cédula del titular en el serialNumber del sujeto. Se genera una vez por
     * titular y PIN, y se reutiliza.
     */
    function haciendaP12(string $holder = 'CPJ-3-101-123456', string $pin = '1234', int $days = 730): string
    {
        static $cache = [];

        return $cache["{$holder}|{$pin}|{$days}"] ??= (function () use ($holder, $pin, $days) {
            $config = [...haciendaOpensslConfig(), 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

            $key = openssl_pkey_new($config);
            $csr = openssl_csr_new([
                'countryName' => 'CR',
                'organizationName' => 'PERSONA JURIDICA',
                'commonName' => 'EMPRESA DE PRUEBA SOCIEDAD ANONIMA',
                'serialNumber' => $holder,
            ], $key, $config);
            $certificate = openssl_csr_sign($csr, null, $key, $days, $config, random_int(1000, 999999));

            openssl_x509_export($certificate, $pem);
            openssl_pkcs12_export($pem, $p12, $key, $pin);

            return $p12;
        })();
    }

    /**
     * Enciende la facturación electrónica de la compañía de salesFixture() en
     * pruebas (sandbox), con todo lo que pide: emisor, actividad v4.4,
     * credenciales y llave. El cliente queda identificado.
     */
    function enableHacienda(array $f, string $environment = 'stag'): array
    {
        $f['company']->update(['legal_name' => 'Empresa de Prueba Sociedad Anónima', 'trade_name' => 'Empresa de Prueba']);
        $f['activity']->update(['code' => '6201.0']);

        $f['customer']->update([
            'name' => 'Cliente de Prueba S.A.',
            'tax_id' => '3101654321',
            'identification_type' => '02',
            'email' => 'facturas@cliente.cr',
            'phone' => '8888-7777',
        ]);

        $f['settings'] = HaciendaSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $f['company']->id], [
            'environment' => $environment,
            'enabled' => true,
            'enabled_at' => now(),
            'identification_type' => '02',
            'province' => '1',
            'canton' => '01',
            'district' => '08',
            'neighborhood' => 'Barrio Amón',
            'address_details' => '100 metros norte del parque',
            'phone' => '22223333',
            'emails' => ['facturacion@empresa.cr'],
            'branch' => '001',
            'terminal' => '00001',
            'send_to_customer' => true,
        ]);

        $f['credential'] = haciendaCredential($f, $environment);

        return $f;
    }

    function haciendaCredential(array $f, string $environment = 'stag', ?string $p12 = null): HaciendaCredential
    {
        $p12 ??= haciendaP12();
        $inspected = app(CertificateInspector::class)->inspect($p12, '1234', '3101123456');

        $credential = HaciendaCredential::withoutGlobalScopes()->firstOrNew([
            'company_id' => $f['company']->id,
            'environment' => $environment,
        ]);

        $credential->username = 'cpj-3-101-123456@stag.comprobanteselectronicos.go.cr';
        $credential->password = 'clave-del-atv';
        $credential->certificate = base64_encode($inspected['p12']);
        $credential->certificate_pin = '1234';
        $credential->forceFill([...$inspected['metadata'], 'certificate_uploaded_at' => now()])->save();

        return $credential;
    }

    /**
     * Simula a Hacienda. El IDP siempre entrega un token; lo del API de
     * recepción lo dice cada prueba (url => respuesta o secuencia).
     *
     * @param  array<string, mixed>  $api
     */
    function fakeHacienda(array $api = []): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'idp.comprobanteselectronicos.go.cr/*' => Http::response([
                'access_token' => 'token-de-prueba',
                'expires_in' => 300,
                'refresh_token' => 'refresh-de-prueba',
                'refresh_expires_in' => 1800,
            ]),
            ...$api,
        ]);
    }

    /** El cuerpo de GET /recepcion/{clave}, con su MensajeHacienda. */
    function haciendaStatus(string $clave, string $indicator, ?string $detail = null): array
    {
        $code = match ($indicator) {
            'aceptado' => '1',
            'rechazado' => '3',
            default => null,
        };

        $body = ['clave' => $clave, 'fecha' => now()->toIso8601String(), 'ind-estado' => $indicator];

        if ($code !== null) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<MensajeHacienda xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeHacienda">'
                ."<Clave>{$clave}</Clave><NombreEmisor>Empresa</NombreEmisor><TipoIdentificacionEmisor>02</TipoIdentificacionEmisor>"
                .'<NumeroCedulaEmisor>3101123456</NumeroCedulaEmisor>'
                ."<Mensaje>{$code}</Mensaje><DetalleMensaje>".htmlspecialchars($detail ?? 'Este comprobante fue aceptado en el ambiente de pruebas', ENT_XML1).'</DetalleMensaje>'
                .'<MontoTotalImpuesto>3250.00000</MontoTotalImpuesto><TotalFactura>28250.00000</TotalFactura>'
                .'</MensajeHacienda>';

            $body['respuesta-xml'] = base64_encode($xml);
        }

        return $body;
    }

    /**
     * Una venta con todo lo que el comprobante electrónico puede llevar. La
     * fecha del documento es la de hoy en Costa Rica: la emisión electrónica
     * no admite otra.
     */
    function electronicSale(array $f, array $o = []): SalesDocument
    {
        $input = new SalesDocumentInput(
            documentTypeId: $f['salesType']->id,
            fiscalDocumentType: $o['fiscalType'] ?? '01',
            currencyId: $o['currencyId'] ?? $f['company']->local_currency_id,
            saleCondition: $o['condition'] ?? '02',
            documentDate: $o['documentDate'] ?? new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')),
            postingDate: now(),
            lines: $o['lines'] ?? [saleLine($f)],
            exchangeRate: $o['exchangeRate'] ?? 1,
            businessPartnerId: array_key_exists('partnerId', $o) ? $o['partnerId'] : $f['customer']->id,
            creditTermDays: array_key_exists('creditTermDays', $o) ? $o['creditTermDays'] : 30,
            emitterActivityCode: $o['activity'] ?? null,
            branch: $o['branch'] ?? '001',
            terminal: $o['terminal'] ?? '00001',
            payments: $o['payments'] ?? [],
            references: $o['references'] ?? [],
            originalSalesDocumentId: $o['originalId'] ?? null,
            saleConditionOther: $o['conditionOther'] ?? null,
        );

        return app(PostSalesDocumentService::class)->post($f['company'], $input, $o['userId'] ?? null);
    }

    /** Una venta de contado, con la cuenta de cada medio de pago configurada. */
    function cashSale(array $f, array $payments, array $o = []): SalesDocument
    {
        foreach ($payments as $payment) {
            BillingPaymentAccount::firstOrCreate(
                ['company_id' => $f['company']->id, 'method_code' => $payment['method_code']],
                ['account_id' => ChartOfAccount::factory()->create(['company_id' => $f['company']->id])->id],
            );
        }

        return electronicSale($f, [...$o, 'condition' => $o['condition'] ?? '01', 'creditTermDays' => null, 'payments' => $payments]);
    }

    function electronicOf(SalesDocument $document): ElectronicDocument
    {
        return ElectronicDocument::withoutGlobalScopes()
            ->where('sales_document_id', $document->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /** El XML firmado tiene que pasar el XSD oficial completo, firma incluida. */
    function expectValidAgainstXsd(ElectronicDocument $electronic): void
    {
        expect(app(XsdValidator::class)->errors($electronic->signed_xml, $electronic->document_type))->toBe([]);
    }

    /** El XPath de un XML de comprobante, con «fe» como su namespace. */
    function haciendaXpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('fe', $dom->documentElement->namespaceURI);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        return $xpath;
    }

    function haciendaValue(string $xml, string $expression): ?string
    {
        $nodes = haciendaXpath($xml)->query($expression);

        return $nodes->length ? $nodes->item(0)->nodeValue : null;
    }

    /** Los nombres de los hijos de un nodo, en orden: para revisar la secuencia del XSD. */
    function haciendaChildren(string $xml, string $expression): array
    {
        $node = haciendaXpath($xml)->query($expression)->item(0);

        return $node === null ? [] : array_values(array_map(
            fn (DOMNode $child) => $child->localName,
            array_filter(iterator_to_array($node->childNodes), fn ($child) => $child instanceof DOMElement)
        ));
    }
}
