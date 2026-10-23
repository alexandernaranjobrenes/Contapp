<?php

namespace App\Domains\Billing\Support;

/**
 * Catálogos cerrados del Anexo Técnico v4.4 de Hacienda.
 *
 * Viven en código y NO en base de datos a propósito: no son configuración del
 * usuario sino valores que fija la norma. Guardarlos en tablas invitaría a
 * editarlos, y un código inventado hace que Hacienda rechace el comprobante.
 * Cuando la norma cambie, cambia este archivo y el diff queda en el historial.
 *
 * Códigos y etiquetas salen de las enumeraciones del XSD oficial v4.4
 * (resources/hacienda/xsd/v4.4), que es la fuente de verdad: cada
 * `<xs:enumeration>` trae su descripción. Lo que no está ahí (el XSD de la
 * factura no enumera los tipos de comprobante ni las situaciones) sale del
 * documento «Anexos y estructuras». Ver docs/einvoice/01-fuentes.md.
 */
class FiscalCatalogs
{
    /** Nota 3 — tipo de comprobante electrónico. */
    public const DOCUMENT_TYPES = [
        '01' => 'Factura electrónica',
        '02' => 'Nota de débito electrónica',
        '03' => 'Nota de crédito electrónica',
        '04' => 'Tiquete electrónico',
        '08' => 'Factura electrónica de compra',
        '09' => 'Factura electrónica de exportación',
        '10' => 'Recibo electrónico de pago',
    ];

    /**
     * Los que Pivox firma y envía a Hacienda desde Facturación. La factura
     * de compra (08) la emite quien compra, no quien vende: es de Compras. El
     * recibo electrónico de pago (10) no tiene XSD publicado junto con los
     * demás, y emitirlo sin validar su estructura sería adivinar.
     */
    public const ELECTRONIC_TYPES = ['01', '02', '03', '04', '09'];

    /** Nota 4 — tipo de identificación del obligado tributario. */
    public const IDENTIFICATION_TYPES = [
        '01' => 'Cédula física',
        '02' => 'Cédula jurídica',
        '03' => 'DIMEX',
        '04' => 'NITE',
        '05' => 'Extranjero no domiciliado',
        '06' => 'No contribuyente',
    ];

    /**
     * Cuántos dígitos tiene cada identificación nacional. Hacienda rechaza
     * una cédula física de 10 o una jurídica de 9: el número tiene que
     * corresponder a su tipo.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const IDENTIFICATION_LENGTHS = [
        '01' => [9, 9],
        '02' => [10, 10],
        '03' => [11, 12],
        '04' => [10, 10],
    ];

    /** Los que puede tener un emisor: los extranjeros no domiciliados y los no contribuyentes no emiten. */
    public const EMITTER_IDENTIFICATION_TYPES = ['01', '02', '03', '04'];

    /** Nota 5 — condición de la venta (las que admite la factura). */
    public const SALE_CONDITIONS = [
        '01' => 'Contado',
        '02' => 'Crédito',
        '03' => 'Consignación',
        '04' => 'Apartado',
        '05' => 'Arrendamiento con opción de compra',
        '06' => 'Arrendamiento en función financiera',
        '07' => 'Cobro a favor de un tercero',
        '08' => 'Servicios prestados al Estado a crédito',
        '10' => 'Venta a crédito en IVA hasta 90 días (Art. 27 LIVA)',
        '12' => 'Venta de mercancía no nacionalizada',
        '13' => 'Venta de bienes usados no contribuyente',
        '14' => 'Arrendamiento operativo',
        '15' => 'Arrendamiento financiero',
        '99' => 'Otros',
    ];

    /** La 12 no está en el XSD del tiquete ni en el de exportación. */
    public const SALE_CONDITIONS_NOT_IN = [
        '04' => ['12'],
        '09' => ['12'],
    ];

    /**
     * Condiciones que difieren el cobro y por lo tanto exigen plazo. La factura
     * abre partida pendiente en Cuentas por Cobrar solo en estos casos.
     */
    public const CREDIT_CONDITIONS = ['02', '08', '10'];

    /** Nota 6 — medio de pago. */
    public const PAYMENT_METHODS = [
        '01' => 'Efectivo',
        '02' => 'Tarjeta',
        '03' => 'Cheque',
        '04' => 'Transferencia / depósito bancario',
        '05' => 'Recaudado por terceros',
        '06' => 'SINPE Móvil',
        '07' => 'Plataforma digital',
        '99' => 'Otros',
    ];

    /** Hasta 4 medios de pago por comprobante (MedioPago maxOccurs=4). */
    public const MAX_PAYMENT_METHODS = 4;

    /** Nota 8 — tipo de impuesto. */
    public const TAX_CODES = [
        '01' => 'Impuesto al Valor Agregado',
        '02' => 'Impuesto Selectivo de Consumo',
        '03' => 'Impuesto único a los combustibles',
        '04' => 'Impuesto específico de bebidas alcohólicas',
        '05' => 'Impuesto específico sobre bebidas envasadas sin alcohol y jabones de tocador',
        '06' => 'Impuesto a los productos de tabaco',
        '07' => 'IVA (cálculo especial)',
        '08' => 'IVA régimen de bienes usados (factor)',
        '12' => 'Impuesto específico al cemento',
        '99' => 'Otros',
    ];

    /** Los impuestos que son IVA: llevan código de tarifa. */
    public const IVA_TAX_CODES = ['01', '07', '08'];

    /**
     * Nota 8.1 — código de tarifa del IVA con su porcentaje. El porcentaje NO
     * se digita: se deriva del código elegido, porque es la norma la que los
     * empareja y una combinación inventada haría rechazar el comprobante.
     */
    public const IVA_RATES = [
        '01' => ['label' => 'Tarifa 0% (Art. 32, num. 1, RLIVA)', 'percentage' => '0.00'],
        '02' => ['label' => 'Tarifa reducida 1%', 'percentage' => '1.00'],
        '03' => ['label' => 'Tarifa reducida 2%', 'percentage' => '2.00'],
        '04' => ['label' => 'Tarifa reducida 4%', 'percentage' => '4.00'],
        '05' => ['label' => 'Transitorio 0%', 'percentage' => '0.00'],
        '06' => ['label' => 'Transitorio 4%', 'percentage' => '4.00'],
        '07' => ['label' => 'Tarifa transitoria 8%', 'percentage' => '8.00'],
        '08' => ['label' => 'Tarifa general 13%', 'percentage' => '13.00'],
        '09' => ['label' => 'Tarifa reducida 0,5%', 'percentage' => '0.50'],
        '10' => ['label' => 'Tarifa exenta', 'percentage' => '0.00'],
        '11' => ['label' => 'Tarifa 0% sin derecho a crédito', 'percentage' => '0.00'],
    ];

    /** Tarifas que dejan la línea sin impuesto pero por razones distintas. */
    public const EXEMPT_RATE_CODE = '10';

    public const NO_SUBJECT_RATE_CODE = '11';

    /**
     * Nota 15 — unidad de medida, tal como la enumera el XSD (UnidadMedidaType).
     *
     * Los símbolos distinguen mayúsculas: «S» es segundo y «s» siemens, «T»
     * tonelada y «t» tesla, «Cm» comisiones y «cm» centímetro. Las de uso
     * comercial van primero; el resto es el Sistema Internacional completo.
     */
    public const UNITS = [
        'Unid' => 'Unidad',
        'Sp' => 'Servicios profesionales',
        'Spe' => 'Servicios personales',
        'St' => 'Servicios técnicos',
        'Os' => 'Otro tipo de servicio',
        'Al' => 'Alquiler de uso habitacional',
        'Alc' => 'Alquiler de uso comercial',
        'Cm' => 'Comisiones',
        'I' => 'Intereses',
        'Acv' => 'Activo virtual',
        'h' => 'Hora',
        'Min' => 'Minuto',
        'D' => 'Día',
        'Kg' => 'Kilogramo',
        'G' => 'Gramo',
        'T' => 'Tonelada',
        'Qq' => 'Quintal',
        'Oz' => 'Onzas',
        'L' => 'Litro',
        'mL' => 'Mililitro',
        'Gal' => 'Galón',
        'M' => 'Metro',
        'Km' => 'Kilómetro',
        'cm' => 'Centímetro',
        'Mm' => 'Milímetro',
        'Ln' => 'Pulgada',
        'm²' => 'Metro cuadrado',
        'm³' => 'Metro cúbico',
        'kWh' => 'Kilovatios por hora',
        'Kw' => 'Kilovatios',
        'Fa' => 'Fanega de café',
        'Cc' => 'Cajuela de café',
        'Cu' => 'Cuartillos de café',
        'Otros' => 'Otra (describirla en la unidad comercial)',
        'S' => 'Segundo',
        '´' => 'Minuto (de arco)',
        '´´' => 'Segundo (de arco)',
        'º' => 'Grado',
        '°C' => 'Grado Celsius',
        'K' => 'Kelvin',
        'A' => 'Ampere',
        'V' => 'Volt',
        'W' => 'Watt',
        'Ω' => 'Ohm',
        'J' => 'Joule',
        'N' => 'Newton',
        'Pa' => 'Pascal',
        'Hz' => 'Hertz',
        'C' => 'Coulomb',
        'F' => 'Farad',
        'H' => 'Henry',
        's' => 'Siemens',
        'Wb' => 'Weber',
        't' => 'Tesla',
        'Lm' => 'Lumen',
        'Lx' => 'Lux',
        'Cd' => 'Candela',
        'Mol' => 'Mol',
        'Bq' => 'Becquerel',
        'Gy' => 'Gray',
        'Sv' => 'Sievert',
        'Kat' => 'Katal',
        'Rad' => 'Radián',
        'Sr' => 'Estereorradián',
        'Np' => 'Neper',
        'B' => 'Bel',
        'eV' => 'Electronvolt',
        'U' => 'Unidad de masa atómica unificada',
        'Ua' => 'Unidad astronómica',
        '1' => 'Uno (índice de refracción)',
        '1/m' => '1 por metro',
        'm/s' => 'Metro por segundo',
        'm/s²' => 'Metro por segundo cuadrado',
        'kg/m³' => 'Kilogramo por metro cúbico',
        'A/m' => 'Ampere por metro',
        'A/m²' => 'Ampere por metro cuadrado',
        'mol/m³' => 'Mol por metro cúbico',
        'cd/m²' => 'Candela por metro cuadrado',
        'rad/s' => 'Radián por segundo',
        'rad/s²' => 'Radián por segundo cuadrado',
        'N/m' => 'Newton por metro',
        'N·m' => 'Newton metro',
        'Pa·s' => 'Pascal segundo',
        'W/m²' => 'Watt por metro cuadrado',
        'W/sr' => 'Watt por estereorradián',
        'W/(m²·sr)' => 'Watt por metro cuadrado estereorradián',
        'W/(m·K)' => 'Watt por metro kelvin',
        'J/K' => 'Joule por kelvin',
        'J/kg' => 'Joule por kilogramo',
        'J/m³' => 'Joule por metro cúbico',
        'J/mol' => 'Joule por mol',
        'J/(kg·K)' => 'Joule por kilogramo kelvin',
        'J/(mol·K)' => 'Joule por mol kelvin',
        'V/m' => 'Volt por metro',
        'C/kg' => 'Coulomb por kilogramo',
        'C/m²' => 'Coulomb por metro cuadrado',
        'C/m³' => 'Coulomb por metro cúbico',
        'F/m' => 'Farad por metro',
        'H/m' => 'Henry por metro',
        'Gy/s' => 'Gray por segundo',
        'kat/m³' => 'Katal por metro cúbico',
    ];

    /** Nota 20 — código del descuento aplicado a la línea. */
    public const DISCOUNT_CODES = [
        '01' => 'Descuento por regalía',
        '02' => 'Descuento por regalía con IVA cobrado al cliente',
        '03' => 'Descuento por bonificación',
        '04' => 'Descuento por volumen',
        '05' => 'Descuento por temporada (estacional)',
        '06' => 'Descuento promocional',
        '07' => 'Descuento comercial',
        '08' => 'Descuento por frecuencia',
        '09' => 'Descuento sostenido',
        '99' => 'Otros descuentos',
    ];

    /** Nota 10.1 — tipo de documento que respalda la exoneración. */
    public const EXONERATION_DOCUMENT_TYPES = [
        '01' => 'Compras autorizadas por la Dirección General de Tributación',
        '02' => 'Ventas exentas a diplomáticos',
        '03' => 'Autorizado por Ley Especial',
        '04' => 'Exenciones DGH — autorización local genérica',
        '05' => 'Exenciones DGH — Transitorio V (ingeniería, arquitectura, topografía, obra civil)',
        '06' => 'Servicios turísticos inscritos ante el ICT',
        '07' => 'Transitorio XVII (recolección, clasificación y almacenamiento de reciclaje)',
        '08' => 'Exoneración a zona franca',
        '09' => 'Servicios complementarios para la exportación (art. 11 RLIVA)',
        '10' => 'Órgano de las corporaciones municipales',
        '11' => 'Exenciones DGH — autorización de impuesto local concreta',
        '99' => 'Otros',
    ];

    /** Nota 23 — institución que emite la exoneración. */
    public const EXONERATION_INSTITUTIONS = [
        '01' => 'Ministerio de Hacienda',
        '02' => 'Ministerio de Relaciones Exteriores y Culto',
        '03' => 'Ministerio de Agricultura y Ganadería',
        '04' => 'Ministerio de Economía, Industria y Comercio',
        '05' => 'Cruz Roja Costarricense',
        '06' => 'Benemérito Cuerpo de Bomberos de Costa Rica',
        '07' => 'Asociación Obras del Espíritu Santo',
        '08' => 'Federación Cruzada Nacional de Protección al Anciano (Fecrunapa)',
        '09' => 'Escuela de Agricultura de la Región Húmeda (EARTH)',
        '10' => 'Instituto Centroamericano de Administración de Empresas (INCAE)',
        '11' => 'Junta de Protección Social (JPS)',
        '12' => 'Autoridad Reguladora de los Servicios Públicos (Aresep)',
        '99' => 'Otros',
    ];

    /** Nota 10 — tipo del documento de referencia. */
    public const REFERENCE_DOCUMENT_TYPES = [
        '01' => 'Factura electrónica',
        '02' => 'Nota de débito electrónica',
        '03' => 'Nota de crédito electrónica',
        '04' => 'Tiquete electrónico',
        '05' => 'Nota de despacho',
        '06' => 'Contrato',
        '07' => 'Procedimiento',
        '08' => 'Comprobante emitido en contingencia',
        '09' => 'Devolución de mercadería',
        '10' => 'Comprobante electrónico rechazado por Hacienda',
        '11' => 'Sustituye factura rechazada por el receptor',
        '12' => 'Sustituye factura de exportación',
        '13' => 'Facturación mes vencido',
        '14' => 'Comprobante aportado por contribuyente de régimen especial',
        '15' => 'Sustituye una factura electrónica de compra',
        '16' => 'Comprobante de proveedor no domiciliado',
        '17' => 'Nota de crédito a factura electrónica de compra',
        '18' => 'Nota de débito a factura electrónica de compra',
        '99' => 'Otros',
    ];

    /** Nota 9 — razón por la que se emite el documento de referencia. */
    public const REFERENCE_REASONS = [
        '01' => 'Anula documento de referencia',
        '02' => 'Corrige texto del documento de referencia',
        '04' => 'Referencia a otro documento',
        '05' => 'Sustituye comprobante provisional por contingencia',
        '06' => 'Devolución de mercancía',
        '07' => 'Sustituye comprobante electrónico',
        '08' => 'Factura endosada',
        '09' => 'Nota de crédito financiera',
        '10' => 'Nota de débito financiera',
        '11' => 'Proveedor no domiciliado',
        '12' => 'Crédito por exoneración posterior a la facturación',
        '99' => 'Otros',
    ];

    /** Situación del comprobante, dígito 42 de la clave numérica. */
    public const SITUATIONS = [
        '1' => 'Normal',
        '2' => 'Contingencia',
        '3' => 'Sin internet',
    ];

    /**
     * Tipos que se emiten contra una referencia obligatoria: una nota de
     * crédito o débito sin documento que corrija no tiene sentido
     * (InformacionReferencia minOccurs=1 en sus XSD), y una factura de compra
     * respalda un comprobante ajeno.
     */
    public const REQUIRE_REFERENCE = ['02', '03', '08'];

    /**
     * El patrón del correo que fija el XSD (CorreoElectronico). Un correo que
     * Laravel acepta como válido puede no pasar este: se revisa con el de
     * Hacienda antes de ponerlo en el XML.
     */
    public const EMAIL_PATTERN = '/^\s*\w+([-+.\']\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*\s*$/u';

    /**
     * Si el CAByS es de un servicio. Hacienda no le cree al emisor: decide por
     * el código, que sigue la CPC de la ONU (secciones 0 a 4 son bienes, 5 a 9
     * servicios), y con eso cruza TotalServGravados / TotalMercanciasGravadas
     * y los demás pares del resumen. Si el comprobante los reparte distinto,
     * lo rechaza con -111 (docs/einvoice/rejections.md).
     */
    public static function cabysIsService(?string $cabys): bool
    {
        return $cabys !== null && preg_match('/^[5-9]\d{12}$/', $cabys) === 1;
    }

    public static function ivaPercentage(string $rateCode): string
    {
        return self::IVA_RATES[$rateCode]['percentage']
            ?? throw new \InvalidArgumentException("Código de tarifa de IVA desconocido: {$rateCode}.");
    }

    public static function isValidEmail(?string $email): bool
    {
        return $email !== null && mb_strlen($email) <= 160 && preg_match(self::EMAIL_PATTERN, $email) === 1;
    }

    /**
     * Si la identificación tiene los dígitos que corresponden a su tipo. Los
     * tipos 05 y 06 no tienen un largo fijo: solo el tope de 20.
     */
    public static function identificationMatchesType(string $type, string $number): bool
    {
        if (! isset(self::IDENTIFICATION_LENGTHS[$type])) {
            $number = trim($number);

            return $number !== '' && mb_strlen($number) <= 20;
        }

        // Los guiones y espacios con que se suele escribir una cédula
        // («3-101-123456») no cuentan: al XML va solo el número.
        $digits = preg_replace('/\D/', '', $number);
        [$min, $max] = self::IDENTIFICATION_LENGTHS[$type];

        return strlen($digits) >= $min && strlen($digits) <= $max;
    }

    /** El código de actividad económica de la v4.4: cuatro dígitos, punto, uno («6201.0»). */
    public static function isValidActivityCode(?string $code): bool
    {
        return $code !== null && preg_match('/^\d{4}\.\d$/', $code) === 1;
    }
}
