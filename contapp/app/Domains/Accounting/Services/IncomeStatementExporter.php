<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\IncomeStatementLine;
use App\Domains\Accounting\DataTransferObjects\IncomeStatementResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class IncomeStatementExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, IncomeStatementResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Estado de resultados');

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

        $this->addSection($writer, 'Ventas', $result->sales, $result->salesTotal, $bold, $sectionTotal);
        $this->addSection($writer, 'Costo de ventas', $result->costOfSales, $result->costOfSalesTotal, $bold, $sectionTotal);
        $writer->addRow(Row::fromValues(['Utilidad bruta', '', (float) $result->grossProfit], $sectionTotal));
        $writer->addRow(Row::fromValues([]));

        $this->addSection($writer, 'Gastos operativos', $result->operatingExpenses, $result->operatingExpensesTotal, $bold, $sectionTotal);
        $writer->addRow(Row::fromValues(['Utilidad operativa', '', (float) $result->operatingProfit], $sectionTotal));
        $writer->addRow(Row::fromValues([]));

        $this->addSection($writer, 'Otros ingresos', $result->otherIncome, $result->otherIncomeTotal, $bold, $sectionTotal);
        $this->addSection($writer, 'Otros gastos', $result->otherExpense, $result->otherExpenseTotal, $bold, $sectionTotal);

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Utilidad neta del período', '', (float) $result->netProfit], $finalTotal));

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
