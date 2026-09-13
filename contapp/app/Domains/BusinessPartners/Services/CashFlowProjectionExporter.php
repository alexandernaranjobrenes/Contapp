<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\BusinessPartners\DataTransferObjects\CashFlowProjectionResult;
use App\Domains\BusinessPartners\DataTransferObjects\ProjectionCurrencyGroup;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class CashFlowProjectionExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, CashFlowProjectionResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Proyección de cobros y pagos');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $sectionTitle = (new Style())->setFontBold()->setFontSize(11);
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $totalStyle = (new Style())->setFontBold()->setBackgroundColor('F2F2F2');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues(['Cobros esperados (clientes)'], $sectionTitle));
        $this->writeGroups($writer, $result->collections, $result->bucketLabels, $bold, $tableHeader, $totalStyle);

        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Pagos esperados (proveedores)'], $sectionTitle));
        $this->writeGroups($writer, $result->payments, $result->bucketLabels, $bold, $tableHeader, $totalStyle);

        $writer->close();
    }

    /**
     * @param  ProjectionCurrencyGroup[]  $groups
     * @param  array<string, string>  $bucketLabels
     */
    private function writeGroups(Writer $writer, array $groups, array $bucketLabels, Style $bold, Style $tableHeader, Style $totalStyle): void
    {
        if (empty($groups)) {
            $writer->addRow(Row::fromValues(['— Sin partidas pendientes —']));

            return;
        }

        $bucketKeys = array_keys($bucketLabels);

        foreach ($groups as $group) {
            $writer->addRow(Row::fromValues(['Moneda: '.$group->currencyCode], $bold));
            $writer->addRow(Row::fromValues(
                ['Socio', 'Nombre', ...array_values($bucketLabels), 'Total'],
                $tableHeader
            ));

            foreach ($group->rows as $row) {
                $writer->addRow(Row::fromValues([
                    $row->partnerCode, $row->partnerName,
                    ...array_map(fn ($k) => (float) $row->buckets[$k], $bucketKeys),
                    (float) $row->total,
                ]));
            }

            $writer->addRow(Row::fromValues([
                '', 'Totales',
                ...array_map(fn ($k) => (float) $group->bucketTotals[$k], $bucketKeys),
                (float) $group->total,
            ], $totalStyle));

            $writer->addRow(Row::fromValues([]));
        }
    }
}
