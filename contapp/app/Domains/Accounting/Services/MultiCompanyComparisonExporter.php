<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\MultiCompanyComparisonResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class MultiCompanyComparisonExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, MultiCompanyComparisonResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Comparativo de empresas');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues(
            ['Empresa', 'Moneda', 'Activo', 'Pasivo', 'Patrimonio', 'Ventas del período', 'Utilidad neta del período'],
            $tableHeader
        ));

        foreach ($result->rows as $row) {
            $writer->addRow(Row::fromValues([
                $row->companyName, $row->currencyCode,
                (float) $row->assetsTotal, (float) $row->liabilitiesTotal, (float) $row->equityTotal,
                (float) $row->salesTotal, (float) $row->netProfit,
            ]));
        }

        $writer->close();
    }
}
