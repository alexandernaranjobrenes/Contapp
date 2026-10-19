<?php

namespace App\Domains\Billing\Services\Hacienda;

/**
 * Verifica una firma XMLDSig enveloped (la de un comprobante, o la que
 * Hacienda pone en su MensajeHacienda) a partir del XML ya serializado.
 *
 * Es independiente del firmador a propósito: vuelve a leer el texto, ubica
 * cada referencia, recalcula su digest con las transformaciones que declara y
 * verifica SignatureValue con la llave pública del certificado de KeyInfo.
 * Si el firmador y este coinciden por casualidad en un error, la verificación
 * de Hacienda igual lo detectaría; si discrepan, se ve acá antes de enviar.
 *
 * Soporta lo que usan los comprobantes de Costa Rica: C14N 1.0 (con y sin
 * comentarios), C14N exclusiva, enveloped-signature, SHA-1/256/512 y
 * RSA-SHA1/256/512.
 */
class XmlSignatureVerifier
{
    private const DIGESTS = [
        'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
        'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];

    private const SIGNATURES = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => OPENSSL_ALGO_SHA1,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
    ];

    /**
     * @return list<string> los problemas; vacía si la firma es válida
     */
    public function problems(string $xml): array
    {
        $doc = new \DOMDocument;

        if (! @$doc->loadXML($xml)) {
            return ['El XML no está bien formado.'];
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', XadesEpesSigner::DS);

        $signature = $xpath->query('/*/ds:Signature')->item(0);

        if (! $signature instanceof \DOMElement) {
            return ['El documento no tiene firma.'];
        }

        $signedInfo = $xpath->query('./ds:SignedInfo', $signature)->item(0);
        $signatureValue = trim((string) $xpath->query('./ds:SignatureValue', $signature)->item(0)?->textContent);
        $certificate = trim((string) $xpath->query('./ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature)->item(0)?->textContent);

        if (! $signedInfo instanceof \DOMElement || $signatureValue === '' || $certificate === '') {
            return ['La firma no tiene SignedInfo, SignatureValue o el certificado.'];
        }

        $problems = [];

        foreach ($xpath->query('./ds:Reference', $signedInfo) as $index => $reference) {
            $problem = $this->checkReference($doc, $xpath, $signature, $reference);

            if ($problem !== null) {
                $problems[] = 'Referencia '.($index + 1).': '.$problem;
            }
        }

        $canonicalization = $xpath->query('./ds:CanonicalizationMethod', $signedInfo)->item(0)?->getAttribute('Algorithm');
        $method = $xpath->query('./ds:SignatureMethod', $signedInfo)->item(0)?->getAttribute('Algorithm');

        if (! isset(self::SIGNATURES[$method])) {
            return [...$problems, "Algoritmo de firma no soportado: {$method}."];
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s+/', '', $certificate), 64, "\n")."-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($pem);

        if ($publicKey === false) {
            return [...$problems, 'El certificado de la firma no se pudo leer.'];
        }

        $canonical = $this->canonicalize($signedInfo, (string) $canonicalization);
        $decoded = base64_decode(preg_replace('/\s+/', '', $signatureValue), true);

        if ($decoded === false || openssl_verify($canonical, $decoded, $publicKey, self::SIGNATURES[$method]) !== 1) {
            $problems[] = 'SignatureValue no corresponde a SignedInfo.';
        }

        return $problems;
    }

    public function isValid(string $xml): bool
    {
        return $this->problems($xml) === [];
    }

    private function checkReference(\DOMDocument $doc, \DOMXPath $xpath, \DOMElement $signature, \DOMElement $reference): ?string
    {
        $uri = $reference->getAttribute('URI');
        $digestMethod = $xpath->query('./ds:DigestMethod', $reference)->item(0)?->getAttribute('Algorithm');
        $expected = trim((string) $xpath->query('./ds:DigestValue', $reference)->item(0)?->textContent);
        $transforms = [];

        foreach ($xpath->query('./ds:Transforms/ds:Transform', $reference) as $transform) {
            $transforms[] = $transform->getAttribute('Algorithm');
        }

        if (! isset(self::DIGESTS[$digestMethod])) {
            return "algoritmo de digest no soportado ({$digestMethod})";
        }

        if ($uri === '') {
            // Todo el documento, sin la firma (enveloped-signature).
            $copy = new \DOMDocument;
            $copy->loadXML($doc->saveXML());
            $copyXpath = new \DOMXPath($copy);
            $copyXpath->registerNamespace('ds', XadesEpesSigner::DS);

            if (in_array(XadesEpesSigner::ENVELOPED, $transforms, true)) {
                $own = $copyXpath->query('/*/ds:Signature')->item(0);
                $own?->parentNode->removeChild($own);
            }

            $data = $this->canonicalize($copy, $this->canonicalTransform($transforms));
        } elseif (str_starts_with($uri, '#')) {
            $target = $xpath->query('//*[@Id="'.substr($uri, 1).'" or @ID="'.substr($uri, 1).'" or @id="'.substr($uri, 1).'"]')->item(0);

            if (! $target instanceof \DOMElement) {
                return "no se encontró el elemento {$uri}";
            }

            $data = $this->canonicalize($target, $this->canonicalTransform($transforms));
        } else {
            return "referencia externa no soportada ({$uri})";
        }

        $actual = base64_encode(hash(self::DIGESTS[$digestMethod], $data, true));

        return hash_equals($expected, $actual) ? null : "el digest no coincide ({$uri})";
    }

    /** @param  list<string>  $transforms */
    private function canonicalTransform(array $transforms): string
    {
        foreach ($transforms as $transform) {
            if ($transform !== XadesEpesSigner::ENVELOPED) {
                return $transform;
            }
        }

        return XadesEpesSigner::C14N;
    }

    private function canonicalize(\DOMNode $node, string $algorithm): string
    {
        $exclusive = str_contains($algorithm, 'exc-c14n');
        $comments = str_ends_with($algorithm, '#WithComments');

        return (string) $node->C14N($exclusive, $comments);
    }
}
