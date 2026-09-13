<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\TrialBalanceResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * OpenSpout no permite insertar imágenes en un XLSX (no expone ninguna clase
 * Image/addImage) — el logo de la empresa solo se embebe en el PDF; acá el
 * encabezado de identidad de empresa (secc. 6 de CLAUDE.md) va como texto.
 */
class TrialBalanceExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, TrialBalanceResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Balance de comprobación');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues(
            ['Código', 'Descripción', 'Tipo', 'Saldo inicial', 'Débito', 'Crédito', 'Neto del periodo', 'Saldo final'],
            $tableHeader
        ));

        foreach ($result->rows as $row) {
            $description = str_repeat('    ', $row->depth).$row->description;
            $writer->addRow(Row::fromValues(
                [
                    $row->code, $description, $row->accountType,
                    (float) $row->openingBalance, (float) $row->periodDebit, (float) $row->periodCredit,
                    (float) $row->periodNet, (float) $row->closingBalance,
                ],
                $row->isHeader ? $bold : null,
            ));
        }

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues(['', '', 'Totales', '', (float) $result->totalDebit, (float) $result->totalCredit, '', ''], $bold),
        ]);

        $writer->close();
    }
}
