<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\CostAllocationRuleReportResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class CostAllocationRuleReportExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, CostAllocationRuleReportResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Normas de reparto');

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
            $writer->addRow(Row::fromValues(["{$group->ruleCode} — {$group->ruleName}"], $groupHeader));
            $writer->addRow(Row::fromValues(
                ['Centro de costo', '% definido', 'Monto real', '% real', 'Variación (p.p.)'],
                $tableHeader
            ));

            foreach ($group->lines as $line) {
                $writer->addRow(Row::fromValues([
                    "{$line->costCenterCode} — {$line->costCenterName}",
                    (float) $line->definedPercentage,
                    (float) $line->actualAmount,
                    $line->actualPercentage !== null ? (float) $line->actualPercentage : '—',
                    $line->variancePercentagePoints !== null ? (float) $line->variancePercentagePoints : '—',
                ]));
            }

            $writer->addRows([
                Row::fromValues(['', '', (float) $group->totalAmount, '', ''], $bold),
                Row::fromValues([]),
            ]);
        }

        $writer->close();
    }
}
