<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\BusinessPartners\DataTransferObjects\AgingResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class AgingExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, AgingResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Antigüedad de saldos');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
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

        $bucketKeys = array_keys($result->bucketLabels);

        foreach ($result->groups as $group) {
            $writer->addRow(Row::fromValues(['Moneda: '.$group->currencyCode], $bold));
            $writer->addRow(Row::fromValues(
                ['Socio', 'Nombre', ...array_values($result->bucketLabels), 'Total'],
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

        $writer->close();
    }
}
