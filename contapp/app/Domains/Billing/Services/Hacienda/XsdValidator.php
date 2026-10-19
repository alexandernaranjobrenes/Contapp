<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Exceptions\ElectronicInvoicingException;

/**
 * Valida un comprobante contra el XSD oficial v4.4 de su tipo, que viene con
 * el proyecto en resources/hacienda/xsd/v4.4 (ver docs/einvoice/01-fuentes.md,
 * con el SHA-256 de cada archivo).
 *
 * Es el único chequeo que de verdad garantiza que Hacienda no rechace por
 * estructura: el orden de los nodos, los nombres, los largos, las
 * enumeraciones. Un comprobante que no lo pasa no se firma ni se envía, y la
 * venta no se registra (ElectronicInvoicingException deshace la
 * transacción).
 *
 * El XSD exige ds:Signature, así que un XML todavía sin firmar se valida
 * pasando $signed = false: se le pone una firma de relleno (solo para
 * validar, no se guarda) y se revisa todo lo demás.
 */
class XsdValidator
{
    public const SCHEMAS = [
        '01' => 'facturaElectronica.xsd',
        '02' => 'notaDebitoElectronica.xsd',
        '03' => 'notaCreditoElectronica.xsd',
        '04' => 'tiqueteElectronico.xsd',
        '08' => 'facturaElectronicaCompra.xsd',
        '09' => 'facturaElectronicaExportacion.xsd',
        'MR' => 'mensajeReceptor.xsd',
    ];

    /**
     * @return list<string>
     */
    public function errors(string $xml, string $documentType, bool $signed = true): array
    {
        $schema = $this->schemaPath($documentType);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new \DOMDocument;

            if (! $dom->loadXML($xml)) {
                return $this->collect();
            }

            if (! $signed) {
                $this->appendPlaceholderSignature($dom);
            }

            $dom->schemaValidate($schema);

            $errors = $this->collect();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $errors;
    }

    public function assertValid(string $xml, string $documentType, bool $signed = true): void
    {
        $errors = $this->errors($xml, $documentType, $signed);

        if ($errors === []) {
            return;
        }

        throw new ElectronicInvoicingException(
            'El comprobante no cumple el esquema oficial de Hacienda (v4.4), y no se emitió: '.
            implode(' · ', array_slice($errors, 0, 5)).
            (count($errors) > 5 ? ' (y '.(count($errors) - 5).' más)' : '')
        );
    }

    public function schemaPath(string $documentType): string
    {
        $file = self::SCHEMAS[$documentType]
            ?? throw new ElectronicInvoicingException("No hay esquema XSD para el tipo de comprobante {$documentType}.");

        $path = rtrim(config('hacienda.xsd_path'), '/\\').DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            throw new ElectronicInvoicingException("No se encontró el esquema oficial {$file} en ".config('hacienda.xsd_path').'.');
        }

        return $path;
    }

    /**
     * Una ds:Signature mínima, válida para el esquema de XMLDSig, en el lugar
     * de la que todavía no tiene: así se valida TODO lo demás contra el XSD.
     *
     * Filtrar el error «falta Signature» no sirve: con elementos que se
     * repiten (InformacionReferencia, maxOccurs=10) libxml lo informa como si
     * faltara otro elemento, y el filtro dejaría pasar un error real o
     * reportaría uno falso.
     */
    private function appendPlaceholderSignature(\DOMDocument $dom): void
    {
        $ds = XadesEpesSigner::DS;
        $element = fn (string $name, ?string $text = null, array $attributes = []) => tap(
            $dom->createElementNS($ds, 'ds:'.$name, $text ?? ''),
            function (\DOMElement $node) use ($attributes) {
                foreach ($attributes as $key => $value) {
                    $node->setAttribute($key, $value);
                }
            }
        );

        $signature = $element('Signature');
        $signedInfo = $element('SignedInfo');
        $signedInfo->appendChild($element('CanonicalizationMethod', null, ['Algorithm' => XadesEpesSigner::C14N]));
        $signedInfo->appendChild($element('SignatureMethod', null, ['Algorithm' => XadesEpesSigner::RSA_SHA256]));

        $reference = $element('Reference', null, ['URI' => '']);
        $reference->appendChild($element('DigestMethod', null, ['Algorithm' => XadesEpesSigner::SHA256]));
        $reference->appendChild($element('DigestValue', 'AA=='));
        $signedInfo->appendChild($reference);

        $signature->appendChild($signedInfo);
        $signature->appendChild($element('SignatureValue', 'AA=='));

        $dom->documentElement->appendChild($signature);
    }

    /**
     * @return list<string>
     */
    private function collect(): array
    {
        $errors = [];

        foreach (libxml_get_errors() as $error) {
            $errors[] = $this->readable(trim($error->message));
        }

        return array_values(array_unique($errors));
    }

    /**
     * Los mensajes de libxml traen el namespace completo de cada elemento:
     * «Element '{https://cdn…/facturaElectronica}Barrio'». Se deja el nombre.
     */
    private function readable(string $message): string
    {
        return preg_replace('/\{[^}]+\}/', '', $message) ?? $message;
    }
}
