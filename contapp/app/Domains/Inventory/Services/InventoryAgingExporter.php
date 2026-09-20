<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\DataTransferObjects\InventoryAgingResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Misma fuente de datos que el PDF y que la pantalla (InventoryAgingService),
 * como pide el checklist de CLAUDE.md secc. 9.
 */
class InventoryAgingExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, InventoryAgingResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Antigüedad de inventario');

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

        // Resumen por tramo antes del detalle: es lo que se mira primero.
        $writer->addRow(Row::fromValues(['Resumen por antigüedad'], $bold));
        $writer->addRow(Row::fromValues(array_values($result->bucketLabels), $tableHeader));
        $writer->addRow(Row::fromValues(array_map(
            fn (string $key) => (float) ($result->bucketTotals[$key] ?? '0.00'),
            array_keys($result->bucketLabels)
        )));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(
            ['Artículo', 'Descripción', 'Grupo', 'Almacén', 'Existencia', 'Valor local', 'Sin rotar desde', 'Días', 'Tramo', 'Nunca ha salido'],
            $tableHeader
        ));

        foreach ($result->rows as $row) {
            $writer->addRow(Row::fromValues([
                $row->itemCode,
                $row->itemName,
                $row->itemGroup ?? '—',
                $row->warehouseCode,
                (float) $row->quantity,
                (float) $row->valueLocal,
                $row->sinceDate,
                $row->daysIdle,
                $result->bucketLabels[$row->bucket] ?? $row->bucket,
                $row->neverIssued ? 'Sí' : 'No',
            ]));
        }

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues(['', '', '', '', 'Total', (float) $result->totalValueLocal], $bold),
        ]);

        $writer->close();
    }
}
