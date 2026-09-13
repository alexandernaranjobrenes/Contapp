<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\CostCenterReportResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class CostCenterReportExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, CostCenterReportResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Centros de costo');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $groupHeader = (new Style())->setFontBold()->setBackgroundColor('E9EDF5');
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
        ]);

        foreach ($result->groups as $group) {
            $writer->addRow(Row::fromValues(["{$group->costCenterCode} — {$group->costCenterName}"], $groupHeader));
            $writer->addRow(Row::fromValues(['Cuenta', 'Descripción', 'Débito', 'Crédito'], $tableHeader));

            foreach ($group->lines as $line) {
                $writer->addRow(Row::fromValues([
                    $line->accountCode, $line->accountDescription, (float) $line->debit, (float) $line->credit,
                ]));
            }

            $writer->addRows([
                Row::fromValues(['', 'Subtotal', (float) $group->totalDebit, (float) $group->totalCredit], $bold),
                Row::fromValues([]),
            ]);
        }

        $writer->addRow(Row::fromValues(['', 'Totales', (float) $result->grandTotalDebit, (float) $result->grandTotalCredit], $bold));

        $writer->close();
    }
}
