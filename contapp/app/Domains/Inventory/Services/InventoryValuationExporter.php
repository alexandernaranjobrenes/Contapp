<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\DataTransferObjects\InventoryValuationResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Misma fuente de datos que el PDF y que la pantalla (InventoryValuationService),
 * como pide el checklist de CLAUDE.md secc. 9. OpenSpout no permite insertar
 * imágenes en un XLSX, así que el logo solo va en el PDF y acá el encabezado
 * de identidad de empresa va como texto.
 */
class InventoryValuationExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, InventoryValuationResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Existencias valorizadas');

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
            ['Artículo', 'Descripción', 'Grupo', 'Almacén', 'Unidad', 'Existencia', 'Costo unitario', 'Valor local', 'Valor USD'],
            $tableHeader
        ));

        foreach ($result->rows as $row) {
            $writer->addRow(Row::fromValues([
                $row->itemCode,
                $row->itemName,
                $row->itemGroup ?? '—',
                $row->warehouseCode,
                $row->uom ?? '—',
                (float) $row->quantity,
                (float) $row->unitCostLocal,
                (float) $row->valueLocal,
                (float) $row->valueForeign,
            ]));
        }

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues(
                ['', '', '', '', '', '', 'Total', (float) $result->totalValueLocal, (float) $result->totalValueForeign],
                $bold
            ),
        ]);

        $writer->close();
    }
}
