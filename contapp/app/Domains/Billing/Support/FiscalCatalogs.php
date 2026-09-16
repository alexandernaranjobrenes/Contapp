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
 * Las notas referenciadas son las del propio anexo técnico.
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

    /** Nota 4 — tipo de identificación del obligado tributario. */
    public const IDENTIFICATION_TYPES = [
        '01' => 'Cédula física',
        '02' => 'Cédula jurídica',
        '03' => 'DIMEX',
        '04' => 'NITE',
        '05' => 'Extranjero no domiciliado',
        '06' => 'No contribuyente',
    ];

    /** Nota 5 — condición de la venta. */
    public const SALE_CONDITIONS = [
        '01' => 'Contado',
        '02' => 'Crédito',
        '08' => 'Servicios prestados al Estado a crédito',
        '10' => 'Venta a crédito en IVA hasta 90 días (Art. 27 LIVA)',
        '12' => 'Venta de mercancía no nacionalizada',
        '13' => 'Venta de bienes usados no contribuyente',
        '14' => 'Arrendamiento operativo',
        '15' => 'Arrendamiento financiero',
        '99' => 'Otros',
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

    /** Hasta 4 medios de pago por comprobante. */
    public const MAX_PAYMENT_METHODS = 4;

    /** Nota 8 — tipo de impuesto. */
    public const TAX_CODES = [
        '01' => 'Impuesto al Valor Agregado',
        '02' => 'Impuesto Selectivo de Consumo',
        '07' => 'IVA cálculo especial',
        '08' => 'IVA régimen de bienes usados',
        '12' => 'Impuesto específico al cemento',
        '99' => 'Otros',
    ];

    /**
     * Nota 8.1 — código de tarifa del IVA con su porcentaje. El porcentaje NO
     * se digita: se deriva del código elegido, porque es la norma la que los
     * empareja y una combinación inventada haría rechazar el comprobante.
     */
    public const IVA_RATES = [
        '01' => ['label' => 'Tarifa 0% con derecho a crédito (Art. 32)', 'percentage' => '0.00'],
        '02' => ['label' => 'Tarifa reducida 1%', 'percentage' => '1.00'],
        '03' => ['label' => 'Tarifa reducida 2%', 'percentage' => '2.00'],
        '04' => ['label' => 'Tarifa reducida 4%', 'percentage' => '4.00'],
        '05' => ['label' => 'Transitorio 0%', 'percentage' => '0.00'],
        '06' => ['label' => 'Transitorio 4%', 'percentage' => '4.00'],
        '07' => ['label' => 'Transitorio 8%', 'percentage' => '8.00'],
        '08' => ['label' => 'Tarifa general 13%', 'percentage' => '13.00'],
        '09' => ['label' => 'Tarifa reducida 0,5%', 'percentage' => '0.50'],
        '10' => ['label' => 'Tarifa exenta', 'percentage' => '0.00'],
        '11' => ['label' => 'Tarifa 0% sin derecho a crédito', 'percentage' => '0.00'],
    ];

    /** Tarifas que dejan la línea sin impuesto pero por razones distintas. */
    public const EXEMPT_RATE_CODE = '10';

    public const NO_SUBJECT_RATE_CODE = '11';

    /** Nota 15 — unidad de medida. */
    public const UNITS = [
        'Unid' => 'Unidad',
        'Sp' => 'Servicios profesionales',
        'Alc' => 'Alcohol',
        'Cm' => 'Centímetro',
        'Cm2' => 'Centímetro cuadrado',
        'Cm3' => 'Centímetro cúbico',
        'D' => 'Día',
        'Fa' => 'Fanega',
        'G' => 'Gramo',
        'Gal' => 'Galón',
        'h' => 'Hora',
        'kg' => 'Kilogramo',
        'km' => 'Kilómetro',
        'kWh' => 'Kilovatio hora',
        'L' => 'Litro',
        'm' => 'Metro',
        'm2' => 'Metro cuadrado',
        'm3' => 'Metro cúbico',
        'min' => 'Minuto',
        'mL' => 'Mililitro',
        'mm' => 'Milímetro',
        'Os' => 'Otro servicio',
        'Qq' => 'Quintal',
        'Spe' => 'Servicios personales',
        't' => 'Tonelada',
        'Acv' => 'Activo virtual',
    ];

    /** Nota 20 — código del descuento aplicado a la línea. */
    public const DISCOUNT_CODES = [
        '01' => 'Descuento por regalía',
        '02' => 'Regalía o bonificación con IVA cobrado al cliente',
        '03' => 'Descuento por bonificación',
        '04' => 'Descuento por volumen',
        '05' => 'Descuento de temporada',
        '06' => 'Descuento promocional',
        '07' => 'Descuento comercial',
        '08' => 'Descuento por frecuencia',
        '09' => 'Descuento sostenido',
        '99' => 'Otros',
    ];

    /** Nota 10.1 — tipo de documento que respalda la exoneración. */
    public const EXONERATION_DOCUMENT_TYPES = [
        '01' => 'Compras autorizadas por la DGT',
        '02' => 'Ventas exentas a diplomáticos',
        '03' => 'Autorizado por Ley especial',
        '04' => 'Exenciones DGH autorización genérica',
        '05' => 'Transitorio V',
        '06' => 'Transitorio IX',
        '07' => 'Transitorio XVII',
        '08' => 'Exoneración a zona franca',
        '09' => 'Exoneración de servicios turísticos (ICT)',
        '10' => 'Exoneración a servicios de transporte y custodia de valores',
        '11' => 'Exoneración a servicios complementarios de transporte',
        '99' => 'Otros',
    ];

    /** Nota 23 — institución que emite la exoneración. */
    public const EXONERATION_INSTITUTIONS = [
        '01' => 'Ministerio de Hacienda',
        '02' => 'Ministerio de Relaciones Exteriores y Culto',
        '03' => 'Ministerio de Agricultura y Ganadería',
        '04' => 'Ministerio de Economía, Industria y Comercio',
        '05' => 'COMEX',
        '06' => 'PROCOMER',
        '07' => 'Instituto Costarricense de Turismo',
        '08' => 'Sistema Nacional de Áreas de Conservación',
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
        '09' => 'Devolución mercadería',
        '10' => 'Comprobante rechazado por la DGT',
        '11' => 'Sustituye factura de régimen simplificado',
        '12' => 'Factura electrónica de compra',
        '13' => 'Factura electrónica de exportación',
        '14' => 'Comprobante aportado por contribuyente régimen especial',
        '15' => 'Sustituye comprobante provisional por contingencia',
        '16' => 'Comprobante de proveedor no domiciliado',
        '99' => 'Otros',
    ];

    /** Nota 9 — razón por la que se emite el documento de referencia. */
    public const REFERENCE_REASONS = [
        '01' => 'Anula documento de referencia',
        '02' => 'Corrige monto',
        '04' => 'Referencia a otro documento',
        '05' => 'Sustituye comprobante provisional por contingencia',
        '06' => 'Devolución de mercadería',
        '07' => 'Sustituye comprobante electrónico',
        '08' => 'Factura de compra régimen simplificado',
        '09' => 'Nota de crédito financiera',
        '10' => 'Nota de débito financiera',
        '99' => 'Otros',
    ];

    /** Situación del comprobante, dígito 46 de la clave numérica. */
    public const SITUATIONS = [
        '1' => 'Normal',
        '2' => 'Contingencia',
        '3' => 'Sin internet',
    ];

    /**
     * Tipos que se emiten contra una referencia obligatoria: una nota de
     * crédito o débito sin documento que corrija no tiene sentido, y una
     * factura de compra respalda un comprobante ajeno.
     */
    public const REQUIRE_REFERENCE = ['02', '03', '08'];

    public static function ivaPercentage(string $rateCode): string
    {
        return self::IVA_RATES[$rateCode]['percentage']
            ?? throw new \InvalidArgumentException("Código de tarifa de IVA desconocido: {$rateCode}.");
    }
}
