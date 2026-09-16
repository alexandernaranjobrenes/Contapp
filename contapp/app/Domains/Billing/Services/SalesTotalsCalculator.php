<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\DataTransferObjects\SalesTaxInput;
use App\Domains\Billing\Support\FiscalCatalogs;

/**
 * Arma el resumen del comprobante exactamente como lo define el XML v4.4.
 *
 * Lo delicado no es la aritmética sino la CLASIFICACIÓN: cada línea cae en uno
 * de cuatro cubos (gravada, exenta, exonerada, no sujeta) y además se separa
 * entre servicios y mercancías. Esos ocho totales son los que Hacienda cruza,
 * y la diferencia entre "exenta" y "no sujeta" —que parecen lo mismo porque
 * ambas dan impuesto cero— es normativa: la 10 es exenta por ley, la 11 no
 * está sujeta al impuesto, y confundirlas distorsiona la declaración de IVA.
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

            $bucket = $this->bucketFor($line, $lineExonerated);
            $suffix = $line->isService ? 'services' : 'goods';
            $key = "total_{$bucket}_{$suffix}";

            $totals[$key] = bcadd($totals[$key], $subtotal, 5);
            $totals['total_sale'] = bcadd($totals['total_sale'], $line->totalAmount(), 5);
            $totals['total_discounts'] = bcadd($totals['total_discounts'], $line->discountAmount, 5);
            $totals['total_tax'] = bcadd($totals['total_tax'], $lineTax, 5);

            $computedLines[] = [
                'line_number' => $index + 1,
                'input' => $line,
                'total_amount' => $line->totalAmount(),
                'subtotal' => $subtotal,
                'tax_amount' => $lineTax,
                'exonerated_amount' => $lineExonerated,
                'line_total' => bcadd($subtotal, $lineTax, 5),
                'taxes' => $taxes,
                'bucket' => $bucket,
            ];
        }

        $totals['total_net_sale'] = bcsub($totals['total_sale'], $totals['total_discounts'], 5);
        $totals['total_document'] = bcadd($totals['total_net_sale'], $totals['total_tax'], 5);

        return ['lines' => $computedLines, 'totals' => $totals];
    }

    /**
     * @return array<string, mixed>
     */
    private function computeTax(SalesTaxInput $tax, string $base): array
    {
        $amount = $this->money(bcdiv(bcmul($base, $tax->ratePercentage, 10), '100', 10));

        $exonerated = '0.00000';

        if ($tax->isExonerated()) {
            $exonerated = $this->money(
                bcdiv(bcmul($amount, $tax->exoneratedPercentage, 10), '100', 10)
            );
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
            'exonerated_amount' => $exonerated,
            // Lo que de verdad se cobra y se debe a Hacienda.
            'net_amount' => bcsub($amount, $exonerated, 5),
        ];
    }

    /**
     * Una línea exonerada se reporta como exonerada aunque su tarifa fuera
     * gravada: lo que la clasifica es el trato fiscal efectivo, no la tarifa
     * nominal.
     */
    private function bucketFor(SalesLineInput $line, string $exonerated): string
    {
        if (bccomp($exonerated, '0.00000', 5) > 0) {
            return 'exonerated';
        }

        $ivaCodes = array_filter(array_map(fn (SalesTaxInput $t) => $t->ivaRateCode, $line->taxes));

        if (empty($ivaCodes)) {
            return 'no_subject';
        }

        if (in_array(FiscalCatalogs::EXEMPT_RATE_CODE, $ivaCodes, true)) {
            return 'exempt';
        }

        if (array_diff($ivaCodes, [FiscalCatalogs::NO_SUBJECT_RATE_CODE]) === []) {
            return 'no_subject';
        }

        return 'taxed';
    }

    private function money(string $value): string
    {
        return number_format((float) $value, 5, '.', '');
    }
}
