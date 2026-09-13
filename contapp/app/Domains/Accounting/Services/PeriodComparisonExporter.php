<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\PeriodComparisonLine;
use App\Domains\Accounting\DataTransferObjects\PeriodComparisonResult;
use App\Domains\Accounting\DataTransferObjects\PeriodComparisonTotal;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class PeriodComparisonExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, PeriodComparisonResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Comparativo de periodos');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $sectionLabel = (new Style())->setFontBold()->setBackgroundColor('E9EDF5');
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
            Row::fromValues(["Periodo 1: {$result->from1} al {$result->to1}   ·   Periodo 2: {$result->from2} al {$result->to2}"], $bold),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues(['BALANCE GENERAL'], $sectionLabel));
        $this->writeSection($writer, 'Activo', $result->assets, $result->assetsTotal, $tableHeader, $bold);
        $this->writeSection($writer, 'Pasivo', $result->liabilities, $result->liabilitiesTotal, $tableHeader, $bold);
        $this->writeSection($writer, 'Patrimonio', $result->equity, $result->equityTotal, $tableHeader, $bold);
        $this->writeTotalRow($writer, 'Total pasivo + patrimonio', $result->totalLiabilitiesAndEquity, $bold);
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(['ESTADO DE RESULTADOS'], $sectionLabel));
        $this->writeSection($writer, 'Ventas', $result->sales, $result->salesTotal, $tableHeader, $bold);
        $this->writeSection($writer, 'Costo de ventas', $result->costOfSales, $result->costOfSalesTotal, $tableHeader, $bold);
        $this->writeTotalRow($writer, 'Utilidad bruta', $result->grossProfit, $bold);
        $this->writeSection($writer, 'Gastos operativos', $result->operatingExpenses, $result->operatingExpensesTotal, $tableHeader, $bold);
        $this->writeTotalRow($writer, 'Utilidad operativa', $result->operatingProfit, $bold);
        $this->writeSection($writer, 'Otros ingresos', $result->otherIncome, $result->otherIncomeTotal, $tableHeader, $bold);
        $this->writeSection($writer, 'Otros gastos', $result->otherExpense, $result->otherExpenseTotal, $tableHeader, $bold);
        $this->writeTotalRow($writer, 'Utilidad neta', $result->netProfit, $bold);

        $writer->close();
    }

    /**
     * @param  PeriodComparisonLine[]  $lines
     */
    private function writeSection(Writer $writer, string $label, array $lines, PeriodComparisonTotal $total, Style $tableHeader, Style $bold): void
    {
        $writer->addRow(Row::fromValues([$label], $bold));
        $writer->addRow(Row::fromValues(
            ['Cuenta', 'Periodo 1', 'Periodo 2', 'Variación', 'Variación %'],
            $tableHeader
        ));

        foreach ($lines as $line) {
            $description = str_repeat('    ', $line->depth).$line->description;
            $writer->addRow(Row::fromValues([
                $description,
                (float) $line->amounts->period1,
                (float) $line->amounts->period2,
                (float) $line->amounts->variance,
                $line->amounts->variancePercent !== null ? (float) $line->amounts->variancePercent : '—',
            ], $line->isHeader ? $bold : null));
        }

        $this->writeTotalRow($writer, "Total {$label}", $total, $bold);
        $writer->addRow(Row::fromValues([]));
    }

    private function writeTotalRow(Writer $writer, string $label, PeriodComparisonTotal $total, Style $bold): void
    {
        $writer->addRow(Row::fromValues([
            $label,
            (float) $total->period1,
            (float) $total->period2,
            (float) $total->variance,
            $total->variancePercent !== null ? (float) $total->variancePercent : '—',
        ], $bold));
    }
}
