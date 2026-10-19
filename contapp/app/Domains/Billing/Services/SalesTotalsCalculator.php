<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Billing\Support\FiscalDecimal;

/**
 * Arma el resumen del comprobante exactamente como lo define el XML v4.4.
 *
 * Lo delicado no es la aritmética sino la CLASIFICACIÓN: cada línea cae en uno
 * de cuatro cubos (gravada, exenta, exonerada, no sujeta) y además se separa
 * entre servicios y mercancías según su CAByS (FiscalCatalogs::cabysIsService:
 * así lo decide Hacienda). Esos ocho totales son los que Hacienda cruza,
 * y la diferencia entre "exenta" y "no sujeta" —que parecen lo mismo porque
 * ambas dan impuesto cero— es normativa: la 10 es exenta por ley, la 11 no
 * está sujeta al impuesto, y confundirlas distorsiona la declaración de IVA.
 *
 * ── Sobre qué monto se clasifica ─────────────────────────────────────────
 *
 * Sobre el MontoTotal de la línea (cantidad × precio), antes del descuento.
 * El XSD define TotalVenta como la suma de gravado + exento + exonerado + no
 * sujeto, y TotalVentaNeta como TotalVenta − TotalDescuentos: si los cubos
 * se llenaran con el subtotal ya descontado, el descuento se restaría dos
 * veces y Hacienda rechazaría el resumen.
 *
 * ── La exoneración parcial ───────────────────────────────────────────────
 *
 * Una línea exonerada en parte va a dos cubos: la porción exonerada
 * (MontoTotal × tarifa exonerada / tarifa) al exonerado, y el resto al
 * gravado. Exonerada del todo, va entera al exonerado.
 *
 * Todo con bcmath a 5 decimales, que es la precisión del esquema.
 */
class SalesTotalsCalculator
{
    /**
     * @param  SalesLineInput[]  $lines
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     totals: array<string, string>
     * }
     */
    public function calculate(array $lines): array
    {
        $computedLines = [];

        $totals = [
            'total_taxed_services' => '0.00000',
            'total_exempt_services' => '0.00000',
            'total_exonerated_services' => '0.00000',
            'total_no_subject_services' => '0.00000',
            'total_taxed_goods' => '0.00000',
            'total_exempt_goods' => '0.00000',
            'total_exonerated_goods' => '0.00000',
            'total_no_subject_goods' => '0.00000',
            'total_sale' => '0.00000',
            'total_discounts' => '0.00000',
            'total_net_sale' => '0.00000',
            'total_tax' => '0.00000',
            'total_document' => '0.00000',
        ];

        foreach (array_values($lines) as $index => $line) {
            $totalAmount = $line->totalAmount();
            $subtotal = $line->subtotal();

            $taxes = [];
            $lineTax = '0.00000';
            $lineExonerated = '0.00000';

            foreach ($line->taxes as $tax) {
                $computed = $this->computeTax($tax, $subtotal);

                $taxes[] = $computed;
                $lineTax = bcadd($lineTax, $computed['net_amount'], 5);
                $lineExonerated = bcadd($lineExonerated, $computed['exonerated_amount'], 5);
            }

            // Por el CAByS, como lo cruza Hacienda; no por is_service, que
            // es lo que el ERP hace con la línea (bodega, cuenta de ingreso).
            $suffix = FiscalCatalogs::cabysIsService($line->cabysCode) ? 'services' : 'goods';
            $shares = $this->sharesFor($line, $taxes, $totalAmount);

            foreach ($shares as $bucket => $amount) {
                $key = "total_{$bucket}_{$suffix}";
                $totals[$key] = bcadd($totals[$key], $amount, 5);
            }

            $totals['total_sale'] = bcadd($totals['total_sale'], $totalAmount, 5);
            $totals['total_discounts'] = bcadd($totals['total_discounts'], $line->discountAmount, 5);
            $totals['total_tax'] = bcadd($totals['total_tax'], $lineTax, 5);

            $computedLines[] = [
                'line_number' => $index + 1,
                'input' => $line,
                'total_amount' => $totalAmount,
                'subtotal' => $subtotal,
                'tax_amount' => $lineTax,
                'exonerated_amount' => $lineExonerated,
                'line_total' => bcadd($subtotal, $lineTax, 5),
                'taxes' => $taxes,
                'bucket' => array_key_first($shares),
            ];
        }

        $totals['total_net_sale'] = bcsub($totals['total_sale'], $totals['total_discounts'], 5);
        $totals['total_document'] = bcadd($totals['total_net_sale'], $totals['total_tax'], 5);

        return ['lines' => $computedLines, 'totals' => $totals];
    }

    /**
     * El monto del impuesto sobre la base imponible (el subtotal) y, si hay
     * exoneración, la tarifa exonerada en puntos porcentuales como la pide
     * la v4.4 (TarifaExonerada): «13.00» es la exoneración total de la
     * tarifa general. El porcentaje que se digita es del impuesto (100 =
     * todo), así que se convierte: tarifa × porcentaje / 100.
     *
     * @return array<string, mixed>
     */
    private function computeTax(SalesTaxInput $tax, string $base): array
    {
        $amount = FiscalDecimal::percentOf($base, $tax->ratePercentage);

        $exonerated = '0.00000';
        $exoneratedRate = null;

        if ($tax->isExonerated()) {
            $exoneratedRate = self::exoneratedRate($tax->ratePercentage, $tax->exoneratedPercentage);
            $exonerated = FiscalDecimal::percentOf($base, $exoneratedRate);

            // El redondeo de la tarifa no puede exonerar más que el impuesto.
            if (bccomp($exonerated, $amount, 5) > 0) {
                $exonerated = $amount;
            }
        }

        return [
            'tax_code' => $tax->taxCode,
            'iva_rate_code' => $tax->ivaRateCode,
            'rate_percentage' => $tax->ratePercentage,
            'taxable_base' => $base,
            'amount' => $amount,
            'exoneration_document_type' => $tax->exonerationDocumentType,
            'exoneration_document_number' => $tax->exonerationDocumentNumber,
            'exoneration_article' => $tax->exonerationArticle,
            'exoneration_clause' => $tax->exonerationClause,
            'exoneration_institution' => $tax->exonerationInstitution,
            'exoneration_date' => $tax->exonerationDate,
            'exonerated_percentage' => $tax->exoneratedPercentage,
            'exonerated_rate' => $exoneratedRate,
            'exonerated_amount' => $exonerated,
            // Lo que de verdad se cobra y se debe a Hacienda.
            'net_amount' => bcsub($amount, $exonerated, 5),
        ];
    }

    /**
     * TarifaExonerada: los puntos de la tarifa que se exoneran. Es pública
     * porque el XML la vuelve a calcular de lo guardado (tarifa y
     * porcentaje), y tiene que dar exactamente lo mismo.
     */
    public static function exoneratedRate(string $ratePercentage, string $exoneratedPercentage): string
    {
        return FiscalDecimal::round(bcdiv(bcmul($ratePercentage, $exoneratedPercentage, 6), '100', 6), 2);
    }

    /**
     * En qué cubos cae el MontoTotal de la línea. La clave del primero es la
     * clasificación «principal» de la línea (la que se guarda para mostrar).
     *
     * @param  array<int, array<string, mixed>>  $taxes
     * @return array<string, string>
     */
    private function sharesFor(SalesLineInput $line, array $taxes, string $totalAmount): array
    {
        $iva = array_values(array_filter(
            $taxes,
            fn (array $t) => $t['iva_rate_code'] !== null
        ));

        $exonerated = array_values(array_filter($iva, fn (array $t) => $t['exonerated_rate'] !== null));

        if ($exonerated !== []) {
            $tax = $exonerated[0];

            if (bccomp($tax['rate_percentage'], '0', 2) <= 0) {
                return ['exonerated' => $totalAmount];
            }

            $ratio = bcdiv($tax['exonerated_rate'], $tax['rate_percentage'], 10);
            $exoneratedShare = FiscalDecimal::mul($totalAmount, bccomp($ratio, '1', 10) > 0 ? '1' : $ratio);
            $taxedShare = bcsub($totalAmount, $exoneratedShare, 5);

            return bccomp($taxedShare, '0', 5) > 0
                ? ['exonerated' => $exoneratedShare, 'taxed' => $taxedShare]
                : ['exonerated' => $totalAmount];
        }

        $codes = array_map(fn (array $t) => $t['iva_rate_code'], $iva);

        if ($codes === []) {
            return ['no_subject' => $totalAmount];
        }

        if (in_array(FiscalCatalogs::EXEMPT_RATE_CODE, $codes, true)) {
            return ['exempt' => $totalAmount];
        }

        if (array_diff($codes, [FiscalCatalogs::NO_SUBJECT_RATE_CODE]) === []) {
            return ['no_subject' => $totalAmount];
        }

        return ['taxed' => $totalAmount];
    }
}
