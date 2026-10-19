<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Exceptions\CertificateException;
use Illuminate\Support\Str;

/**
 * Firma XAdES-EPES de un comprobante, como la exige Hacienda.
 *
 *   ds:Signature Id=S
 *     ds:SignedInfo                         C14N 1.0 inclusiva, RSA-SHA256
 *       ds:Reference Id=S-ref0 URI=""        el comprobante (enveloped-signature)
 *       ds:Reference URI="#S-keyinfo"        el certificado con que se firma
 *       ds:Reference Type=SignedProperties   las propiedades XAdES
 *     ds:SignatureValue
 *     ds:KeyInfo Id=S-keyinfo               X509Certificate + RSAKeyValue
 *     ds:Object
 *       xades:QualifyingProperties Target=#S
 *         xades:SignedProperties Id=S-signedprops
 *           SigningTime · SigningCertificate (SHA-256 + emisor y serie)
 *           SignaturePolicyIdentifier/SignaturePolicyId   ← lo que hace EPES
 *             SigPolicyId/Identifier = URL de la resolución
 *             SigPolicyHash = SHA-256 del PDF de la resolución
 *           SignerRole/ClaimedRoles/ClaimedRole = Emisor
 *           SignedDataObjectProperties/DataObjectFormat → S-ref0, text/xml
 *
 * ── Lo que ya costó rechazos (guía, L17 y L21) ───────────────────────────
 *
 * - «La firma del documento no tiene el Policy Id»: una política implícita
 *   (SignaturePolicyImplied) no basta; va el identificador con su hash.
 * - Todo el árbol de la firma se ADJUNTA al documento antes de calcular los
 *   digests: canonicalizar un nodo suelto da otros bytes que los que verá
 *   Hacienda, porque los namespaces heredados del comprobante (el de la
 *   factura y el de ds) entran en la forma canónica.
 * - El digest del comprobante se calcula SIN la firma, que es lo que hace la
 *   transformación enveloped-signature al verificar.
 * - El XML firmado se serializa tal cual: reformatearlo después rompe la
 *   firma.
 *
 * Usa DOM y openssl, sin librerías: son ~200 líneas, y una librería XMLDSig
 * genérica no arma la parte XAdES de todos modos.
 */
class XadesEpesSigner implements HaciendaSigner
{
    public const DS = 'http://www.w3.org/2000/09/xmldsig#';

    public const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    public const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    public const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public const SIGNED_PROPERTIES_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    public function __construct(private readonly CertificateInspector $inspector) {}

    public function sign(string $xml, SigningKey $key, ?\DateTimeInterface $signingTime = null): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;

        if (! $doc->loadXML($xml)) {
            throw new CertificateException('El XML que se quiere firmar no está bien formado.');
        }

        $root = $doc->documentElement;

        // 1. El digest del comprobante, antes de que tenga la firma adentro:
        //    es lo que obtiene quien verifica al aplicar enveloped-signature.
        $documentDigest = $this->digest($doc->C14N(false, false));

        $id = 'xmldsig-'.Str::uuid()->toString();
        $refId = $id.'-ref0';
        $keyInfoId = $id.'-keyinfo';
        $signedPropertiesId = $id.'-signedprops';

        // 2. El árbol completo de la firma, adjunto al documento, con los
        //    DigestValue y el SignatureValue todavía vacíos.
        $signature = $this->ds($doc, 'Signature');
        $signature->setAttribute('Id', $id);
        $root->appendChild($signature);

        $signedInfo = $this->ds($doc, 'SignedInfo');
        $signature->appendChild($signedInfo);
        $signedInfo->appendChild($this->algorithm($doc, 'CanonicalizationMethod', self::C14N));
        $signedInfo->appendChild($this->algorithm($doc, 'SignatureMethod', self::RSA_SHA256));

        $documentReference = $this->reference($doc, $signedInfo, '', [self::ENVELOPED]);
        $documentReference->setAttribute('Id', $refId);
        $keyInfoReference = $this->reference($doc, $signedInfo, '#'.$keyInfoId, [self::C14N]);
        $propertiesReference = $this->reference($doc, $signedInfo, '#'.$signedPropertiesId, [self::C14N]);
        $propertiesReference->setAttribute('Type', self::SIGNED_PROPERTIES_TYPE);

        $signatureValue = $this->ds($doc, 'SignatureValue');
        $signatureValue->setAttribute('Id', $id.'-sigvalue');
        $signature->appendChild($signatureValue);

        $keyInfo = $this->keyInfo($doc, $key, $keyInfoId);
        $signature->appendChild($keyInfo);

        $object = $this->ds($doc, 'Object');
        $object->setAttribute('Id', $id.'-object');
        $signature->appendChild($object);

        $signedProperties = $this->qualifyingProperties($doc, $object, $key, $id, $signedPropertiesId, $refId, $signingTime);

        // 3. Los digests de KeyInfo y SignedProperties, ya en su lugar.
        $this->setDigest($documentReference, $documentDigest);
        $this->setDigest($keyInfoReference, $this->digest($keyInfo->C14N(false, false)));
        $this->setDigest($propertiesReference, $this->digest($signedProperties->C14N(false, false)));

        // 4. La firma de SignedInfo, ya con los tres digests.
        $signed = '';

        if (! openssl_sign($signedInfo->C14N(false, false), $signed, $key->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new CertificateException('OpenSSL no pudo firmar: '.(openssl_error_string() ?: 'error desconocido').'.');
        }

        $signatureValue->appendChild($doc->createTextNode(base64_encode($signed)));

        return $doc->saveXML();
    }

    private function qualifyingProperties(
        \DOMDocument $doc,
        \DOMElement $object,
        SigningKey $key,
        string $signatureId,
        string $signedPropertiesId,
        string $refId,
        ?\DateTimeInterface $signingTime,
    ): \DOMElement {
        $qualifying = $this->xades($doc, 'QualifyingProperties');
        $qualifying->setAttribute('Id', $signatureId.'-qualifyingprops');
        $qualifying->setAttribute('Target', '#'.$signatureId);
        $object->appendChild($qualifying);

        $signedProperties = $this->xades($doc, 'SignedProperties');
        $signedProperties->setAttribute('Id', $signedPropertiesId);
        $qualifying->appendChild($signedProperties);

        $signatureProperties = $this->xades($doc, 'SignedSignatureProperties');
        $signedProperties->appendChild($signatureProperties);

        $time = \DateTimeImmutable::createFromInterface($signingTime ?? now())
            ->setTimezone(new \DateTimeZone(config('hacienda.timezone')));
        $signatureProperties->appendChild($this->xades($doc, 'SigningTime', $time->format('Y-m-d\TH:i:sP')));

        // El certificado con que se firmó: su SHA-256, y quién lo emitió.
        $parsed = openssl_x509_parse($key->certificatePem);
        $fingerprint = openssl_x509_fingerprint($key->certificatePem, 'sha256', true);

        if ($parsed === false || $fingerprint === false) {
            throw new CertificateException('El certificado de la llave no se pudo leer.');
        }

        $signingCertificate = $this->xades($doc, 'SigningCertificate');
        $signatureProperties->appendChild($signingCertificate);
        $cert = $this->xades($doc, 'Cert');
        $signingCertificate->appendChild($cert);

        $certDigest = $this->xades($doc, 'CertDigest');
        $cert->appendChild($certDigest);
        $certDigest->appendChild($this->algorithm($doc, 'DigestMethod', self::SHA256));
        $certDigest->appendChild($this->ds($doc, 'DigestValue', base64_encode($fingerprint)));

        $issuerSerial = $this->xades($doc, 'IssuerSerial');
        $cert->appendChild($issuerSerial);
        $issuerSerial->appendChild($this->ds($doc, 'X509IssuerName', $this->inspector->distinguishedName($parsed['issuer'] ?? [])));
        $issuerSerial->appendChild($this->ds($doc, 'X509SerialNumber', $this->inspector->serialDecimal($parsed)));

        // La política: lo que convierte la firma en EPES.
        $policyIdentifier = $this->xades($doc, 'SignaturePolicyIdentifier');
        $signatureProperties->appendChild($policyIdentifier);
        $policyId = $this->xades($doc, 'SignaturePolicyId');
        $policyIdentifier->appendChild($policyId);

        $sigPolicyId = $this->xades($doc, 'SigPolicyId');
        $policyId->appendChild($sigPolicyId);
        $sigPolicyId->appendChild($this->xades($doc, 'Identifier', config('hacienda.signature_policy.url')));
        $sigPolicyId->appendChild($this->xades($doc, 'Description'));

        $sigPolicyHash = $this->xades($doc, 'SigPolicyHash');
        $policyId->appendChild($sigPolicyHash);
        $sigPolicyHash->appendChild($this->algorithm($doc, 'DigestMethod', self::SHA256));
        $sigPolicyHash->appendChild($this->ds($doc, 'DigestValue', config('hacienda.signature_policy.digest_sha256')));

        $signerRole = $this->xades($doc, 'SignerRole');
        $signatureProperties->appendChild($signerRole);
        $claimedRoles = $this->xades($doc, 'ClaimedRoles');
        $signerRole->appendChild($claimedRoles);
        $claimedRoles->appendChild($this->xades($doc, 'ClaimedRole', 'Emisor'));

        $dataObjectProperties = $this->xades($doc, 'SignedDataObjectProperties');
        $signedProperties->appendChild($dataObjectProperties);
        $format = $this->xades($doc, 'DataObjectFormat');
        $format->setAttribute('ObjectReference', '#'.$refId);
        $dataObjectProperties->appendChild($format);
        $format->appendChild($this->xades($doc, 'MimeType', 'text/xml'));
        $format->appendChild($this->xades($doc, 'Encoding', 'UTF-8'));

        return $signedProperties;
    }

    private function keyInfo(\DOMDocument $doc, SigningKey $key, string $keyInfoId): \DOMElement
    {
        $keyInfo = $this->ds($doc, 'KeyInfo');
        $keyInfo->setAttribute('Id', $keyInfoId);

        $x509Data = $this->ds($doc, 'X509Data');
        $keyInfo->appendChild($x509Data);
        $x509Data->appendChild($this->ds($doc, 'X509Certificate', $this->derBase64($key->certificatePem)));

        $details = openssl_pkey_get_details($key->privateKey);

        if ($details === false || ! isset($details['rsa'])) {
            throw new CertificateException('La llave no es RSA.');
        }

        $keyValue = $this->ds($doc, 'KeyValue');
        $keyInfo->appendChild($keyValue);
        $rsaKeyValue = $this->ds($doc, 'RSAKeyValue');
        $keyValue->appendChild($rsaKeyValue);
        $rsaKeyValue->appendChild($this->ds($doc, 'Modulus', base64_encode($details['rsa']['n'])));
        $rsaKeyValue->appendChild($this->ds($doc, 'Exponent', base64_encode($details['rsa']['e'])));

        return $keyInfo;
    }

    /**
     * @param  list<string>  $transforms
     */
    private function reference(\DOMDocument $doc, \DOMElement $signedInfo, string $uri, array $transforms): \DOMElement
    {
        $reference = $this->ds($doc, 'Reference');
        $reference->setAttribute('URI', $uri);
        $signedInfo->appendChild($reference);

        $transformsNode = $this->ds($doc, 'Transforms');
        $reference->appendChild($transformsNode);

        foreach ($transforms as $transform) {
            $transformsNode->appendChild($this->algorithm($doc, 'Transform', $transform));
        }

        $reference->appendChild($this->algorithm($doc, 'DigestMethod', self::SHA256));
        $reference->appendChild($this->ds($doc, 'DigestValue'));

        return $reference;
    }

    private function setDigest(\DOMElement $reference, string $digest): void
    {
        foreach ($reference->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'DigestValue') {
                $child->appendChild($reference->ownerDocument->createTextNode($digest));

                return;
            }
        }
    }

    private function algorithm(\DOMDocument $doc, string $name, string $algorithm): \DOMElement
    {
        $element = $this->ds($doc, $name);
        $element->setAttribute('Algorithm', $algorithm);

        return $element;
    }

    private function ds(\DOMDocument $doc, string $name, ?string $text = null): \DOMElement
    {
        $element = $doc->createElementNS(self::DS, 'ds:'.$name);

        if ($text !== null) {
            $element->appendChild($doc->createTextNode($text));
        }

        return $element;
    }

    private function xades(\DOMDocument $doc, string $name, ?string $text = null): \DOMElement
    {
        $element = $doc->createElementNS(self::XADES, 'xades:'.$name);

        if ($text !== null) {
            $element->appendChild($doc->createTextNode($text));
        }

        return $element;
    }

    private function digest(string $data): string
    {
        return base64_encode(hash('sha256', $data, true));
    }

    private function derBase64(string $certificatePem): string
    {
        return preg_replace('/\s+|-----(BEGIN|END) CERTIFICATE-----/', '', $certificatePem) ?? '';
    }
}
