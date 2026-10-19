<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\InvalidSalesDocumentException;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Models\SalesDocumentLine;
use App\Domains\Billing\Models\SalesLineTax;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Billing\Support\FiscalDecimal;
use App\Domains\Billing\Support\HaciendaClock;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;

/**
 * Construye el XML del comprobante según los XSD oficiales v4.4
 * (resources/hacienda/xsd/v4.4), sin firmar.
 *
 * Los XSD son SECUENCIALES: el orden de los nodos importa tanto como su
 * nombre. El de cada método es el del esquema, y los rechazos reales que ya
 * costó equivocarse (guía de implementación, secc. 3.9) están anotados donde
 * aplican: ProveedorSistemas como texto (L1, L2), BaseImponible antes de
 * Impuesto (L6), Impuesto siempre (L7), ImpuestoAsumidoEmisorFabrica (L8),
 * TipoCambio siempre (L9), No Sujeto aparte de Exento (L10),
 * TotalDesgloseImpuesto (L11), MedioPago antes de TotalComprobante (L12),
 * TipoDocIR / FechaEmisionIR (L13).
 *
 * Cada tipo de comprobante tiene su raíz y su namespace (un namespace fijo
 * para todos hacía rechazar las notas de crédito), y el de exportación no
 * lleva base imponible, exoneración ni impuesto neto: lo resuelve PROFILES.
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

    /**
     * Lo que cambia entre los XSD. Lo que no está acá es igual en todos.
     *
     * - receiver_activity: CodigoActividadReceptor (no existe en el tiquete
     *   ni en la de exportación).
     * - receiver_location: Receptor/Ubicacion (la de exportación solo admite
     *   OtrasSenasExtranjero).
     * - full_tax: BaseImponible, Exoneracion, ImpuestoAsumidoEmisorFabrica e
     *   ImpuestoNeto en la línea, y los totales de exonerado y no sujeto en
     *   el resumen. La de exportación no tiene ninguno.
     */
    private const PROFILES = [
        '01' => ['receiver_activity' => true, 'receiver_location' => true, 'full_tax' => true],
        '02' => ['receiver_activity' => true, 'receiver_location' => true, 'full_tax' => true],
        '03' => ['receiver_activity' => true, 'receiver_location' => true, 'full_tax' => true],
        '04' => ['receiver_activity' => false, 'receiver_location' => true, 'full_tax' => true],
        '08' => ['receiver_activity' => true, 'receiver_location' => true, 'full_tax' => true],
        '09' => ['receiver_activity' => false, 'receiver_location' => false, 'full_tax' => false],
        '10' => ['receiver_activity' => true, 'receiver_location' => true, 'full_tax' => true],
    ];

    /**
     * @param  bool  $pretty  con sangría, solo para mostrarlo: el que se firma
     *                        va sin formato (reformatear un XML firmado rompe
     *                        la firma)
     */
    public function build(SalesDocument $document, Company $company, ?HaciendaSetting $settings = null, bool $pretty = false): string
    {
        $type = $document->fiscal_document_type;

        if (! isset(self::ROOT_ELEMENTS[$type])) {
            throw new InvalidSalesDocumentException("No hay estructura XML definida para el tipo de comprobante {$type}.");
        }

        if ($document->status === 'draft' && $document->clave === null) {
            throw new InvalidSalesDocumentException('Un comprobante en borrador todavía no tiene clave ni consecutivo que firmar.');
        }

        $document->loadMissing(['lines.taxes', 'payments', 'references', 'businessPartner', 'currency']);
        $settings ??= HaciendaSetting::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->first();
        $profile = self::PROFILES[$type];

        [$rootName, $schema] = self::ROOT_ELEMENTS[$type];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = $pretty;

        $root = $dom->createElementNS(self::NAMESPACE_BASE.'/'.$schema, $rootName);
        $dom->appendChild($root);

        $this->appendHeader($dom, $root, $document, $company, $settings, $profile);
        $this->appendEmitter($dom, $root, $company, $settings);
        $this->appendReceiver($dom, $root, $document, $profile);

        $this->add($dom, $root, 'CondicionVenta', $document->sale_condition);

        if ($document->sale_condition === '99' && filled($document->sale_condition_other)) {
            $this->add($dom, $root, 'CondicionVentaOtros', mb_substr($document->sale_condition_other, 0, 100));
        }

        if ($document->credit_term_days !== null) {
            $this->add($dom, $root, 'PlazoCredito', (string) $document->credit_term_days);
        }

        $detail = $dom->createElement('DetalleServicio');
        $root->appendChild($detail);

        foreach ($document->lines as $line) {
            $detail->appendChild($this->lineElement($dom, $line, $profile));
        }

        $root->appendChild($this->summaryElement($dom, $document, $profile));

        foreach ($document->references as $reference) {
            $node = $dom->createElement('InformacionReferencia');
            $this->add($dom, $node, 'TipoDocIR', $reference->document_type);
            $this->add($dom, $node, 'Numero', mb_substr((string) $reference->number, 0, 50));
            // Obligatoria en la v4.4 (minOccurs=1).
            $this->add($dom, $node, 'FechaEmisionIR', HaciendaClock::format(
                $reference->issued_at ?? HaciendaClock::startOfLocalDay($document->document_date)
            ));
            $this->add($dom, $node, 'Codigo', $reference->reason_code);
            $this->add($dom, $node, 'Razon', mb_substr((string) $reference->reason, 0, 180));
            $root->appendChild($node);
        }

        return $dom->saveXML();
    }

    /**
     * Lo que tiene que cuadrar antes de firmar. Hacienda hace estas mismas
     * cuentas y rechaza el comprobante si no dan; detectarlo acá cuesta un
     * mensaje en pantalla y no un consecutivo quemado.
     *
     * @return list<string>
     */
    public function invariantProblems(SalesDocument $document): array
    {
        $document->loadMissing(['lines.taxes', 'payments']);
        $problems = [];

        $sale = '0';
        $discounts = '0';
        $tax = '0';

        foreach ($document->lines as $line) {
            $expectedTotal = FiscalDecimal::mul((string) $line->quantity, (string) $line->unit_price);

            if (bccomp($expectedTotal, (string) $line->total_amount, 5) !== 0) {
                $problems[] = "Línea {$line->line_number}: el monto total no es cantidad × precio.";
            }

            $expectedSubtotal = bcsub((string) $line->total_amount, (string) $line->discount_amount, 5);

            if (bccomp($expectedSubtotal, (string) $line->subtotal, 5) !== 0) {
                $problems[] = "Línea {$line->line_number}: el subtotal no es monto total − descuento.";
            }

            $lineTax = '0';

            foreach ($line->taxes as $lineTaxRow) {
                $lineTax = bcadd($lineTax, (string) $lineTaxRow->net_amount, 5);
            }

            if (bccomp($lineTax, (string) $line->tax_amount, 5) !== 0) {
                $problems[] = "Línea {$line->line_number}: el impuesto neto no es la suma de sus impuestos.";
            }

            if (bccomp(bcadd((string) $line->subtotal, (string) $line->tax_amount, 5), (string) $line->line_total, 5) !== 0) {
                $problems[] = "Línea {$line->line_number}: el total de la línea no es subtotal + impuesto neto.";
            }

            $sale = bcadd($sale, (string) $line->total_amount, 5);
            $discounts = bcadd($discounts, (string) $line->discount_amount, 5);
            $tax = bcadd($tax, (string) $line->tax_amount, 5);
        }

        $buckets = '0';

        foreach (['taxed', 'exempt', 'exonerated', 'no_subject'] as $bucket) {
            foreach (['services', 'goods'] as $kind) {
                $buckets = bcadd($buckets, (string) $document->{"total_{$bucket}_{$kind}"}, 5);
            }
        }

        $checks = [
            'TotalVenta no es la suma de los montos de las líneas' => [$sale, $document->total_sale],
            'TotalVenta no es gravado + exento + exonerado + no sujeto' => [$buckets, $document->total_sale],
            'TotalDescuentos no es la suma de los descuentos' => [$discounts, $document->total_discounts],
            'TotalVentaNeta no es TotalVenta − TotalDescuentos' => [bcsub((string) $document->total_sale, (string) $document->total_discounts, 5), $document->total_net_sale],
            'TotalImpuesto no es la suma de los impuestos netos' => [$tax, $document->total_tax],
            'TotalComprobante no es TotalVentaNeta + TotalImpuesto' => [bcadd((string) $document->total_net_sale, (string) $document->total_tax, 5), $document->total_document],
        ];

        foreach ($checks as $message => [$expected, $actual]) {
            if (bccomp((string) $expected, (string) $actual, 5) !== 0) {
                $problems[] = $message.'.';
            }
        }

        if ($document->payments->isNotEmpty()) {
            $paid = $document->payments->reduce(fn (string $carry, $p) => bcadd($carry, (string) $p->amount, 5), '0');

            if (bccomp(FiscalDecimal::round($paid, 2), FiscalDecimal::round((string) $document->total_document, 2), 2) !== 0) {
                $problems[] = 'Los medios de pago no suman el total del comprobante.';
            }
        }

        return $problems;
    }

    /**
     * Valida el XML contra un XSD. Devuelve la lista de errores; vacía
     * significa que el esquema lo acepta. Para los comprobantes, usar
     * XsdValidator, que sabe qué esquema corresponde a cada tipo.
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

    /** La identificación del emisor tal como va al XML y al cuerpo del envío. */
    public static function emitterIdentification(Company $company, ?HaciendaSetting $settings): array
    {
        $number = preg_replace('/\D/', '', (string) $company->tax_id);
        $type = $settings?->identification_type ?: (strlen($number) === 9 ? '01' : '02');

        return ['type' => $type, 'number' => $number];
    }

    /** La del receptor: null si el comprobante no lleva (tiquete a consumidor final). */
    public static function receiverIdentification(SalesDocument $document): ?array
    {
        $partner = $document->businessPartner;

        if (! $partner || ! $partner->identification_type || ! filled($partner->tax_id)) {
            return null;
        }

        $number = isset(FiscalCatalogs::IDENTIFICATION_LENGTHS[$partner->identification_type])
            ? preg_replace('/\D/', '', (string) $partner->tax_id)
            : mb_substr(trim((string) $partner->tax_id), 0, 20);

        return ['type' => $partner->identification_type, 'number' => $number];
    }

    private function appendHeader(\DOMDocument $dom, \DOMElement $root, SalesDocument $document, Company $company, ?HaciendaSetting $settings, array $profile): void
    {
        $this->add($dom, $root, 'Clave', $document->clave);

        // Texto simple (L2): la cédula de quien provee el sistema.
        $provider = preg_replace('/\D/', '', (string) config('hacienda.system_provider_id'));
        $this->add($dom, $root, 'ProveedorSistemas', $provider !== '' ? $provider : self::emitterIdentification($company, $settings)['number']);

        $this->add($dom, $root, 'CodigoActividadEmisor', $document->emitter_activity_code);

        if ($profile['receiver_activity'] && FiscalCatalogs::isValidActivityCode($document->receiver_activity_code)) {
            $this->add($dom, $root, 'CodigoActividadReceptor', $document->receiver_activity_code);
        }

        $this->add($dom, $root, 'NumeroConsecutivo', $document->consecutive);
        $this->add($dom, $root, 'FechaEmision', HaciendaClock::format(
            $document->issued_at ?? HaciendaClock::startOfLocalDay($document->document_date)
        ));
    }

    private function appendEmitter(\DOMDocument $dom, \DOMElement $root, Company $company, ?HaciendaSetting $settings): void
    {
        $node = $dom->createElement('Emisor');
        $this->add($dom, $node, 'Nombre', mb_substr((string) $company->legal_name, 0, 100));

        $id = self::emitterIdentification($company, $settings);
        $identification = $dom->createElement('Identificacion');
        $this->add($dom, $identification, 'Tipo', $id['type']);
        $this->add($dom, $identification, 'Numero', $id['number']);
        $node->appendChild($identification);

        $tradeName = trim((string) $company->trade_name);

        if (mb_strlen($tradeName) >= 3) {
            $this->add($dom, $node, 'NombreComercial', mb_substr($tradeName, 0, 80));
        }

        if ($settings && $settings->province && $settings->canton && $settings->district && mb_strlen((string) $settings->address_details) >= 5) {
            $node->appendChild($this->locationElement(
                $dom, $settings->province, $settings->canton, $settings->district, $settings->neighborhood, $settings->address_details
            ));
        }

        $this->appendPhone($dom, $node, $settings?->phone);

        foreach (array_slice(array_filter($settings?->emailList() ?? [], [FiscalCatalogs::class, 'isValidEmail']), 0, 4) as $email) {
            $this->add($dom, $node, 'CorreoElectronico', $email);
        }

        $root->appendChild($node);
    }

    private function appendReceiver(\DOMDocument $dom, \DOMElement $root, SalesDocument $document, array $profile): void
    {
        $partner = $document->businessPartner;

        // El tiquete electrónico a consumidor final no lleva receptor.
        if (! $partner) {
            return;
        }

        $node = $dom->createElement('Receptor');
        $this->add($dom, $node, 'Nombre', mb_substr((string) $partner->name, 0, 100));

        $id = self::receiverIdentification($document);

        if ($id !== null) {
            $identification = $dom->createElement('Identificacion');
            $this->add($dom, $identification, 'Tipo', $id['type']);
            $this->add($dom, $identification, 'Numero', $id['number']);
            $node->appendChild($identification);
        }

        $province = (string) $partner->province;
        $canton = (string) $partner->canton;
        $district = (string) $partner->district;
        $details = trim((string) $partner->address_details);

        // La ubicación del receptor es opcional: va solo si está completa y
        // con los códigos que pide el XSD (1 + 2 + 2 dígitos). Un texto libre
        // («San José») no es un código.
        $hasLocation = preg_match('/^\d$/', $province) && preg_match('/^\d{2}$/', $canton)
            && preg_match('/^\d{2}$/', $district) && mb_strlen($details) >= 5;

        if ($profile['receiver_location'] && $hasLocation && ($id['type'] ?? null) !== '05') {
            $node->appendChild($this->locationElement($dom, $province, $canton, $district, null, $details));
        } elseif (($id['type'] ?? null) === '05' && mb_strlen($details) >= 5) {
            $this->add($dom, $node, 'OtrasSenasExtranjero', mb_substr($details, 0, 300));
        }

        $this->appendPhone($dom, $node, $partner->phone);

        if (FiscalCatalogs::isValidEmail($partner->email)) {
            $this->add($dom, $node, 'CorreoElectronico', trim($partner->email));
        }

        $root->appendChild($node);
    }

    private function locationElement(\DOMDocument $dom, string $province, string $canton, string $district, ?string $neighborhood, string $details): \DOMElement
    {
        $location = $dom->createElement('Ubicacion');
        $this->add($dom, $location, 'Provincia', $province);
        $this->add($dom, $location, 'Canton', str_pad($canton, 2, '0', STR_PAD_LEFT));
        $this->add($dom, $location, 'Distrito', str_pad($district, 2, '0', STR_PAD_LEFT));

        // Barrio es texto de 5 a 50 (L4: un código de dos dígitos lo rechaza).
        $neighborhood = trim((string) $neighborhood);

        if (mb_strlen($neighborhood) >= 5) {
            $this->add($dom, $location, 'Barrio', mb_substr($neighborhood, 0, 50));
        }

        $this->add($dom, $location, 'OtrasSenas', mb_substr(trim($details), 0, 250));

        return $location;
    }

    private function appendPhone(\DOMDocument $dom, \DOMElement $node, ?string $phone): void
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        // Un número de Costa Rica con el 506 adelante: el código de país va aparte.
        if (strlen($digits) === 11 && str_starts_with($digits, '506')) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) < 8 || strlen($digits) > 20) {
            return;
        }

        $telephone = $dom->createElement('Telefono');
        $this->add($dom, $telephone, 'CodigoPais', '506');
        $this->add($dom, $telephone, 'NumTelefono', $digits);
        $node->appendChild($telephone);
    }

    private function lineElement(\DOMDocument $dom, SalesDocumentLine $line, array $profile): \DOMElement
    {
        $node = $dom->createElement('LineaDetalle');

        $this->add($dom, $node, 'NumeroLinea', (string) $line->line_number);
        $this->add($dom, $node, 'CodigoCABYS', $line->cabys_code);

        if (filled($line->item_code)) {
            // Tipo 01: el código con que el vendedor identifica su producto.
            $commercial = $dom->createElement('CodigoComercial');
            $this->add($dom, $commercial, 'Tipo', '01');
            $this->add($dom, $commercial, 'Codigo', mb_substr((string) $line->item_code, 0, 20));
            $node->appendChild($commercial);
        }

        $this->add($dom, $node, 'Cantidad', FiscalDecimal::format((string) $line->quantity, 3));
        $this->add($dom, $node, 'UnidadMedida', $line->unit_code);
        $this->add($dom, $node, 'Detalle', mb_substr((string) $line->description, 0, 200));

        // Antes del precio (en el XSD va con los datos del producto, no al final).
        if (filled($line->vin_or_serial)) {
            $this->add($dom, $node, 'NumeroVINoSerie', mb_substr((string) $line->vin_or_serial, 0, 17));
        }

        $this->add($dom, $node, 'PrecioUnitario', FiscalDecimal::format((string) $line->unit_price));
        $this->add($dom, $node, 'MontoTotal', FiscalDecimal::format((string) $line->total_amount));

        if (! FiscalDecimal::isZero((string) $line->discount_amount)) {
            $discount = $dom->createElement('Descuento');
            $this->add($dom, $discount, 'MontoDescuento', FiscalDecimal::format((string) $line->discount_amount));
            $this->add($dom, $discount, 'CodigoDescuento', $line->discount_code);

            $reason = trim((string) $line->discount_reason);

            if ($line->discount_code === '99' && mb_strlen($reason) >= 5) {
                $this->add($dom, $discount, 'CodigoDescuentoOTRO', mb_substr($reason, 0, 100));
            }

            // «Obligatorio si existe descuento» (anotación del XSD): sin un
            // texto propio, va el nombre del código.
            $nature = mb_strlen($reason) >= 3 ? $reason : (FiscalCatalogs::DISCOUNT_CODES[$line->discount_code] ?? 'Descuento');
            $this->add($dom, $discount, 'NaturalezaDescuento', mb_substr($nature, 0, 80));

            $node->appendChild($discount);
        }

        $this->add($dom, $node, 'SubTotal', FiscalDecimal::format((string) $line->subtotal));

        // L6: la base imponible va ANTES del impuesto.
        if ($profile['full_tax']) {
            $this->add($dom, $node, 'BaseImponible', FiscalDecimal::format((string) $line->subtotal));
        }

        $grossTax = '0';

        // L7: el nodo Impuesto va siempre, también en una línea exenta.
        foreach ($line->taxes as $tax) {
            $node->appendChild($this->taxElement($dom, $tax, $profile));
            $grossTax = bcadd($grossTax, (string) $tax->amount, 5);
        }

        if ($profile['full_tax']) {
            // L8: obligatorio en la factura; cero cuando no aplica.
            $this->add($dom, $node, 'ImpuestoAsumidoEmisorFabrica', FiscalDecimal::format('0'));
            $this->add($dom, $node, 'ImpuestoNeto', FiscalDecimal::format((string) $line->tax_amount));
            $this->add($dom, $node, 'MontoTotalLinea', FiscalDecimal::format((string) $line->line_total));
        } else {
            $this->add($dom, $node, 'MontoTotalLinea', FiscalDecimal::format(bcadd((string) $line->subtotal, $grossTax, 5)));
        }

        return $node;
    }

    private function taxElement(\DOMDocument $dom, SalesLineTax $tax, array $profile): \DOMElement
    {
        $node = $dom->createElement('Impuesto');
        $this->add($dom, $node, 'Codigo', $tax->tax_code);

        if ($tax->iva_rate_code) {
            $this->add($dom, $node, 'CodigoTarifaIVA', $tax->iva_rate_code);
        }

        $this->add($dom, $node, 'Tarifa', FiscalDecimal::format((string) $tax->rate_percentage, 2));
        $this->add($dom, $node, 'Monto', FiscalDecimal::format((string) $tax->amount));

        if ($profile['full_tax'] && ! FiscalDecimal::isZero((string) $tax->exonerated_amount)) {
            $exoneration = $dom->createElement('Exoneracion');
            $this->add($dom, $exoneration, 'TipoDocumentoEX1', $tax->exoneration_document_type);
            $this->add($dom, $exoneration, 'NumeroDocumento', mb_substr((string) $tax->exoneration_document_number, 0, 40));

            // Artículo e inciso son enteros en el XSD: un «3 bis» no cabe.
            if (ctype_digit((string) $tax->exoneration_article)) {
                $this->add($dom, $exoneration, 'Articulo', (string) (int) $tax->exoneration_article);
            }

            if (ctype_digit((string) $tax->exoneration_clause)) {
                $this->add($dom, $exoneration, 'Inciso', (string) (int) $tax->exoneration_clause);
            }

            $this->add($dom, $exoneration, 'NombreInstitucion', $tax->exoneration_institution);

            if ($tax->exoneration_date) {
                $this->add($dom, $exoneration, 'FechaEmisionEX', HaciendaClock::format(HaciendaClock::startOfLocalDay($tax->exoneration_date)));
            }

            $this->add($dom, $exoneration, 'TarifaExonerada', SalesTotalsCalculator::exoneratedRate(
                (string) $tax->rate_percentage, (string) ($tax->exonerated_percentage ?? '0')
            ));
            $this->add($dom, $exoneration, 'MontoExoneracion', FiscalDecimal::format((string) $tax->exonerated_amount));
            $node->appendChild($exoneration);
        }

        return $node;
    }

    private function summaryElement(\DOMDocument $dom, SalesDocument $document, array $profile): \DOMElement
    {
        $node = $dom->createElement('ResumenFactura');

        // L9: TipoCambio siempre, también en colones (1.00000).
        $currency = $dom->createElement('CodigoTipoMoneda');
        $this->add($dom, $currency, 'CodigoMoneda', $document->currency?->code ?? 'CRC');
        $this->add($dom, $currency, 'TipoCambio', FiscalDecimal::format((string) $document->exchange_rate));
        $node->appendChild($currency);

        $value = fn (string $column) => FiscalDecimal::format((string) $document->{$column});
        $sum = fn (string ...$columns) => FiscalDecimal::format(
            array_reduce($columns, fn (string $carry, string $c) => bcadd($carry, (string) $document->{$c}, 5), '0')
        );

        // L10: gravado, exento, exonerado y no sujeto, cada uno en su nodo.
        $this->add($dom, $node, 'TotalServGravados', $value('total_taxed_services'));
        $this->add($dom, $node, 'TotalServExentos', $value('total_exempt_services'));

        if ($profile['full_tax']) {
            $this->add($dom, $node, 'TotalServExonerado', $value('total_exonerated_services'));
            $this->add($dom, $node, 'TotalServNoSujeto', $value('total_no_subject_services'));
        }

        $this->add($dom, $node, 'TotalMercanciasGravadas', $value('total_taxed_goods'));
        $this->add($dom, $node, 'TotalMercanciasExentas', $value('total_exempt_goods'));

        if ($profile['full_tax']) {
            $this->add($dom, $node, 'TotalMercExonerada', $value('total_exonerated_goods'));
            $this->add($dom, $node, 'TotalMercNoSujeta', $value('total_no_subject_goods'));
        }

        $this->add($dom, $node, 'TotalGravado', $sum('total_taxed_services', 'total_taxed_goods'));
        $this->add($dom, $node, 'TotalExento', $sum('total_exempt_services', 'total_exempt_goods'));

        if ($profile['full_tax']) {
            $this->add($dom, $node, 'TotalExonerado', $sum('total_exonerated_services', 'total_exonerated_goods'));
            $this->add($dom, $node, 'TotalNoSujeto', $sum('total_no_subject_services', 'total_no_subject_goods'));
        }

        $this->add($dom, $node, 'TotalVenta', $value('total_sale'));
        $this->add($dom, $node, 'TotalDescuentos', $value('total_discounts'));
        $this->add($dom, $node, 'TotalVentaNeta', $value('total_net_sale'));

        // L11: un desglose por cada combinación de impuesto y tarifa.
        foreach ($this->taxBreakdown($document, $profile) as $breakdown) {
            $item = $dom->createElement('TotalDesgloseImpuesto');
            $this->add($dom, $item, 'Codigo', $breakdown['code']);

            if ($breakdown['rate_code'] !== null) {
                $this->add($dom, $item, 'CodigoTarifaIVA', $breakdown['rate_code']);
            }

            $this->add($dom, $item, 'TotalMontoImpuesto', FiscalDecimal::format($breakdown['amount']));
            $node->appendChild($item);
        }

        $this->add($dom, $node, 'TotalImpuesto', $value('total_tax'));

        // L12: los medios de pago van al final, justo antes del total.
        foreach ($document->payments as $payment) {
            $method = $dom->createElement('MedioPago');
            $this->add($dom, $method, 'TipoMedioPago', $payment->method_code);

            if ($payment->method_code === '99' && mb_strlen(trim((string) $payment->method_other)) >= 3) {
                $this->add($dom, $method, 'MedioPagoOtros', mb_substr(trim($payment->method_other), 0, 100));
            }

            $this->add($dom, $method, 'TotalMedioPago', FiscalDecimal::format((string) $payment->amount));
            $node->appendChild($method);
        }

        $this->add($dom, $node, 'TotalComprobante', $value('total_document'));

        return $node;
    }

    /**
     * @return list<array{code: string, rate_code: ?string, amount: string}>
     */
    private function taxBreakdown(SalesDocument $document, array $profile): array
    {
        $groups = [];

        foreach ($document->lines as $line) {
            foreach ($line->taxes as $tax) {
                $key = $tax->tax_code.'|'.($tax->iva_rate_code ?? '');

                $groups[$key] ??= ['code' => $tax->tax_code, 'rate_code' => $tax->iva_rate_code, 'amount' => '0'];

                // Lo cobrado: neto de exoneración, para que la suma del
                // desglose sea TotalImpuesto. La de exportación no exonera.
                $groups[$key]['amount'] = bcadd(
                    $groups[$key]['amount'],
                    (string) ($profile['full_tax'] ? $tax->net_amount : $tax->amount),
                    5
                );
            }
        }

        return array_values($groups);
    }

    private function add(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $value): void
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode((string) $value));
        $parent->appendChild($element);
    }
}
