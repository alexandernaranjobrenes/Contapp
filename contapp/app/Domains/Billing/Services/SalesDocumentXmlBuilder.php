<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Models\SalesDocumentLine;
use App\Domains\Core\Models\Company;

/**
 * Construye el XML del comprobante según el Anexo Técnico v4.4.
 *
 * IMPORTANTE — límite conocido y deliberado: este generador se escribió a
 * partir de la estructura documentada del anexo, pero NO se validó contra el
 * XSD oficial, que no está disponible en este entorno. Antes de emitir contra
 * producción hay que correr `validateAgainstSchema()` con el .xsd que publica
 * Hacienda: un nombre de nodo o un orden equivocado hace que la DGT rechace el
 * comprobante, y eso solo lo detecta el esquema real.
 *
 * Se usa DOMDocument y no concatenación de strings a propósito: escapa el
 * contenido por sí solo, y un nombre de cliente con "&" o una descripción con
 * "<" romperían un XML armado a mano sin que nadie lo note hasta el rechazo.
 */
class SalesDocumentXmlBuilder
{
    private const NAMESPACE_BASE = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4';

    /** Nodo raíz y espacio de nombres según el tipo de comprobante (Nota 3). */
    private const ROOT_ELEMENTS = [
        '01' => ['FacturaElectronica', 'facturaElectronica'],
        '02' => ['NotaDebitoElectronica', 'notaDebitoElectronica'],
        '03' => ['NotaCreditoElectronica', 'notaCreditoElectronica'],
        '04' => ['TiqueteElectronico', 'tiqueteElectronico'],
        '08' => ['FacturaElectronicaCompra', 'facturaElectronicaCompra'],
        '09' => ['FacturaElectronicaExportacion', 'facturaElectronicaExportacion'],
        '10' => ['ReciboElectronicoPago', 'reciboElectronicoPago'],
    ];

    public function build(SalesDocument $document, Company $company): string
    {
        if (! isset(self::ROOT_ELEMENTS[$document->fiscal_document_type])) {
            throw new InvalidSalesDocumentException(
                "No hay estructura XML definida para el tipo de comprobante {$document->fiscal_document_type}."
            );
        }

        if ($document->status === 'draft') {
            throw new InvalidSalesDocumentException('Un comprobante en borrador todavía no tiene clave ni consecutivo que firmar.');
        }

        $document->loadMissing(['lines.taxes', 'payments', 'references', 'businessPartner', 'currency']);

        [$rootName, $schema] = self::ROOT_ELEMENTS[$document->fiscal_document_type];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NAMESPACE_BASE.'/'.$schema, $rootName);
        $dom->appendChild($root);

        $this->appendHeader($dom, $root, $document);
        $this->appendEmitter($dom, $root, $company, $document);
        $this->appendReceiver($dom, $root, $document);

        $this->add($dom, $root, 'CondicionVenta', $document->sale_condition);

        if ($document->credit_term_days !== null) {
            $this->add($dom, $root, 'PlazoCredito', (string) $document->credit_term_days);
        }

        $detail = $dom->createElement('DetalleServicio');
        $root->appendChild($detail);

        foreach ($document->lines as $line) {
            $detail->appendChild($this->lineElement($dom, $line));
        }

        $root->appendChild($this->summaryElement($dom, $document));

        foreach ($document->references as $reference) {
            $node = $dom->createElement('InformacionReferencia');
            $this->add($dom, $node, 'TipoDocIR', $reference->document_type);
            $this->add($dom, $node, 'Numero', $reference->number);

            if ($reference->issued_at) {
                $this->add($dom, $node, 'FechaEmisionIR', $reference->issued_at->format('Y-m-d\TH:i:sP'));
            }

            $this->add($dom, $node, 'Codigo', $reference->reason_code);
            $this->add($dom, $node, 'Razon', $reference->reason);
            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * Valida el XML contra el XSD oficial. Devuelve la lista de errores; vacía
     * significa que el esquema lo acepta. Es el único chequeo que de verdad
     * garantiza que la DGT no lo rechace por estructura.
     *
     * @return string[]
     */
    public function validateAgainstSchema(string $xml, string $xsdPath): array
    {
        if (! is_file($xsdPath)) {
            throw new InvalidSalesDocumentException("No se encontró el esquema XSD en {$xsdPath}.");
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $dom->schemaValidate($xsdPath);

        $errors = array_map(
            fn (\LibXMLError $error) => trim($error->message).' (línea '.$error->line.')',
            libxml_get_errors()
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $errors;
    }

    private function appendHeader(\DOMDocument $dom, \DOMElement $root, SalesDocument $document): void
    {
        $this->add($dom, $root, 'Clave', $document->clave);
        $this->add($dom, $root, 'CodigoActividadEmisor', $document->emitter_activity_code);

        if ($document->receiver_activity_code) {
            $this->add($dom, $root, 'CodigoActividadReceptor', $document->receiver_activity_code);
        }

        $this->add($dom, $root, 'NumeroConsecutivo', $document->consecutive);
        $this->add($dom, $root, 'FechaEmision', $document->document_date->format('Y-m-d\TH:i:sP'));
    }

    private function appendEmitter(\DOMDocument $dom, \DOMElement $root, Company $company, SalesDocument $document): void
    {
        $node = $dom->createElement('Emisor');
        $this->add($dom, $node, 'Nombre', $company->legal_name);

        $identification = $dom->createElement('Identificacion');
        // El emisor de una empresa es siempre jurídico (tipo 02); una persona
        // física inscrita usaría 01, y eso lo define su propia cédula.
        $this->add($dom, $identification, 'Tipo', strlen(preg_replace('/\D/', '', (string) $company->tax_id)) === 9 ? '01' : '02');
        $this->add($dom, $identification, 'Numero', preg_replace('/\D/', '', (string) $company->tax_id));
        $node->appendChild($identification);

        if ($company->trade_name) {
            $this->add($dom, $node, 'NombreComercial', $company->trade_name);
        }

        $root->appendChild($node);
    }

    private function appendReceiver(\DOMDocument $dom, \DOMElement $root, SalesDocument $document): void
    {
        $partner = $document->businessPartner;

        // El tiquete electrónico a consumidor final no lleva receptor.
        if (! $partner) {
            return;
        }

        $node = $dom->createElement('Receptor');
        $this->add($dom, $node, 'Nombre', $partner->name);

        if ($partner->identification_type && $partner->tax_id) {
            $identification = $dom->createElement('Identificacion');
            $this->add($dom, $identification, 'Tipo', $partner->identification_type);
            $this->add($dom, $identification, 'Numero', preg_replace('/\D/', '', $partner->tax_id));
            $node->appendChild($identification);
        }

        if ($partner->phone) {
            $phone = $dom->createElement('Telefono');
            $this->add($dom, $phone, 'CodigoPais', '506');
            $this->add($dom, $phone, 'NumTelefono', preg_replace('/\D/', '', $partner->phone));
            $node->appendChild($phone);
        }

        if ($partner->email) {
            $this->add($dom, $node, 'CorreoElectronico', $partner->email);
        }

        $root->appendChild($node);
    }

    private function lineElement(\DOMDocument $dom, SalesDocumentLine $line): \DOMElement
    {
        $node = $dom->createElement('LineaDetalle');

        $this->add($dom, $node, 'NumeroLinea', (string) $line->line_number);
        $this->add($dom, $node, 'CodigoCABYS', $line->cabys_code);

        if ($line->item_code) {
            $commercial = $dom->createElement('CodigoComercial');
            $this->add($dom, $commercial, 'Tipo', '01');
            $this->add($dom, $commercial, 'Codigo', $line->item_code);
            $node->appendChild($commercial);
        }

        $this->add($dom, $node, 'Cantidad', $this->decimal($line->quantity, 3));
        $this->add($dom, $node, 'UnidadMedida', $line->unit_code);
        $this->add($dom, $node, 'Detalle', $line->description);
        $this->add($dom, $node, 'PrecioUnitario', $this->decimal($line->unit_price));
        $this->add($dom, $node, 'MontoTotal', $this->decimal($line->total_amount));

        if (bccomp((string) $line->discount_amount, '0.00000', 5) > 0) {
            $discount = $dom->createElement('Descuento');
            $this->add($dom, $discount, 'MontoDescuento', $this->decimal($line->discount_amount));
            $this->add($dom, $discount, 'CodigoDescuento', $line->discount_code);

            if ($line->discount_reason) {
                $this->add($dom, $discount, 'NaturalezaDescuento', $line->discount_reason);
            }

            $node->appendChild($discount);
        }

        $this->add($dom, $node, 'SubTotal', $this->decimal($line->subtotal));

        foreach ($line->taxes as $tax) {
            $taxNode = $dom->createElement('Impuesto');
            $this->add($dom, $taxNode, 'Codigo', $tax->tax_code);

            if ($tax->iva_rate_code) {
                $this->add($dom, $taxNode, 'CodigoTarifaIVA', $tax->iva_rate_code);
            }

            $this->add($dom, $taxNode, 'Tarifa', $this->decimal($tax->rate_percentage, 2));
            $this->add($dom, $taxNode, 'Monto', $this->decimal($tax->amount));

            if (bccomp((string) $tax->exonerated_amount, '0.00000', 5) > 0) {
                $exoneration = $dom->createElement('Exoneracion');
                $this->add($dom, $exoneration, 'TipoDocumentoEX', $tax->exoneration_document_type);
                $this->add($dom, $exoneration, 'NumeroDocumento', $tax->exoneration_document_number);

                if ($tax->exoneration_article) {
                    $this->add($dom, $exoneration, 'Articulo', $tax->exoneration_article);
                }

                if ($tax->exoneration_clause) {
                    $this->add($dom, $exoneration, 'Inciso', $tax->exoneration_clause);
                }

                if ($tax->exoneration_institution) {
                    $this->add($dom, $exoneration, 'NombreInstitucion', $tax->exoneration_institution);
                }

                if ($tax->exoneration_date) {
                    $this->add($dom, $exoneration, 'FechaEmisionEX', $tax->exoneration_date->format('Y-m-d\TH:i:sP'));
                }

                $this->add($dom, $exoneration, 'TarifaExonerada', $this->decimal($tax->exonerated_percentage ?? '0', 2));
                $this->add($dom, $exoneration, 'MontoExoneracion', $this->decimal($tax->exonerated_amount));
                $taxNode->appendChild($exoneration);
            }

            $node->appendChild($taxNode);
        }

        $this->add($dom, $node, 'ImpuestoNeto', $this->decimal($line->tax_amount));
        $this->add($dom, $node, 'MontoTotalLinea', $this->decimal($line->line_total));

        if ($line->vin_or_serial) {
            $this->add($dom, $node, 'NumeroVINoSerie', $line->vin_or_serial);
        }

        return $node;
    }

    private function summaryElement(\DOMDocument $dom, SalesDocument $document): \DOMElement
    {
        $node = $dom->createElement('ResumenFactura');

        $currency = $dom->createElement('CodigoTipoMoneda');
        $this->add($dom, $currency, 'CodigoMoneda', $document->currency?->code ?? 'CRC');
        $this->add($dom, $currency, 'TipoCambio', $this->decimal($document->exchange_rate));
        $node->appendChild($currency);

        $map = [
            'TotalServGravados' => 'total_taxed_services',
            'TotalServExentos' => 'total_exempt_services',
            'TotalServExonerado' => 'total_exonerated_services',
            'TotalServNoSujeto' => 'total_no_subject_services',
            'TotalMercanciasGravadas' => 'total_taxed_goods',
            'TotalMercanciasExentas' => 'total_exempt_goods',
            'TotalMercExonerada' => 'total_exonerated_goods',
            'TotalMercNoSujeta' => 'total_no_subject_goods',
        ];

        foreach ($map as $element => $column) {
            $this->add($dom, $node, $element, $this->decimal($document->{$column}));
        }

        $this->add($dom, $node, 'TotalGravado', $this->decimal(
            bcadd((string) $document->total_taxed_services, (string) $document->total_taxed_goods, 5)
        ));
        $this->add($dom, $node, 'TotalExento', $this->decimal(
            bcadd((string) $document->total_exempt_services, (string) $document->total_exempt_goods, 5)
        ));
        $this->add($dom, $node, 'TotalExonerado', $this->decimal(
            bcadd((string) $document->total_exonerated_services, (string) $document->total_exonerated_goods, 5)
        ));
        $this->add($dom, $node, 'TotalVenta', $this->decimal($document->total_sale));
        $this->add($dom, $node, 'TotalDescuentos', $this->decimal($document->total_discounts));
        $this->add($dom, $node, 'TotalVentaNeta', $this->decimal($document->total_net_sale));
        $this->add($dom, $node, 'TotalImpuesto', $this->decimal($document->total_tax));
        $this->add($dom, $node, 'TotalComprobante', $this->decimal($document->total_document));

        // En v4.4 los medios de pago viven dentro del resumen, uno por nodo.
        foreach ($document->payments as $payment) {
            $method = $dom->createElement('MedioPago');
            $this->add($dom, $method, 'TipoMedioPago', $payment->method_code);
            $this->add($dom, $method, 'TotalMedioPago', $this->decimal($payment->amount));
            $node->appendChild($method);
        }

        return $node;
    }

    private function add(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $value): void
    {
        $parent->appendChild($dom->createElement($name, htmlspecialchars((string) $value, ENT_XML1)));
    }

    private function decimal(string|float|int $value, int $scale = 5): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
