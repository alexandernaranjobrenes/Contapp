<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\BalanceSheetResult;
use App\Domains\Accounting\DataTransferObjects\IncomeStatementLine;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class BalanceSheetExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, BalanceSheetResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Balance general');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $sectionTotal = (new Style())->setFontBold()->setBackgroundColor('F2F2F2');
        $finalTotal = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
        ]);

        $this->addSection($writer, 'Activo', $result->assets, $result->assetsTotal, $bold, $sectionTotal);
        $writer->addRow(Row::fromValues([]));

        $this->addSection($writer, 'Pasivo', $result->liabilities, $result->liabilitiesTotal, $bold, $sectionTotal);
        $writer->addRow(Row::fromValues([]));

        $this->addSection($writer, 'Patrimonio', $result->equity, $result->equityTotal, $bold, $sectionTotal);
        $writer->addRow(Row::fromValues(['Utilidad (pérdida) del ejercicio', '', (float) $result->currentYearEarnings]));
        $writer->addRow(Row::fromValues(['Total patrimonio', '', (float) $result->totalEquityAndEarnings], $sectionTotal));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['Total pasivo + patrimonio', '', (float) $result->totalLiabilitiesAndEquity], $finalTotal));

        if (! $result->isBalanced) {
            $warning = (new Style())->setFontBold()->setFontColor('CC0000');
            $writer->addRow(Row::fromValues(['⚠ El activo no cuadra contra pasivo + patrimonio — revisar.'], $warning));
        }

        $writer->close();
    }

    /**
     * @param  IncomeStatementLine[]  $lines
     */
    private function addSection(Writer $writer, string $label, array $lines, string $total, Style $bold, Style $sectionTotal): void
    {
        $writer->addRow(Row::fromValues([$label], $bold));

        foreach ($lines as $line) {
            $description = str_repeat('    ', $line->depth).$line->description;
            $writer->addRow(Row::fromValues(
                [$line->code, $description, (float) $line->amount],
                $line->isHeader ? $bold : null,
            ));
        }

        $writer->addRow(Row::fromValues(['Total '.mb_strtolower($label), '', (float) $total], $sectionTotal));
    }
}
