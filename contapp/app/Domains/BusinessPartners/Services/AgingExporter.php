<?php

namespace App\Domains\BusinessPartners\Services;

use App\Domains\BusinessPartners\DataTransferObjects\AgingResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class AgingExporter
{
    /**
     * $includeDocuments agrega, debajo de cada socio, el detalle de sus
     * documentos (número, vencimiento, monto original, saldo, tramo) — lo
     * mismo que la ficha que se abre al tocar un socio en pantalla, para
     * quien necesita el XLSX/PDF con ese nivel sin tener que abrir CONTAPP.
     */
    public function writeTo(string $outputPath, ReportHeader $header, AgingResult $result, bool $includeDocuments = false): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Antigüedad de saldos');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $docHeader = (new Style())->setFontBold()->setBackgroundColor('E8EDF5');
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

                if ($includeDocuments && $row->documents !== []) {
                    $writer->addRow(Row::fromValues(
                        ['', 'Documento', 'Vencimiento', 'Monto original', 'Saldo pendiente', 'Tramo'],
                        $docHeader
                    ));

                    foreach ($row->documents as $doc) {
                        $writer->addRow(Row::fromValues([
                            '', $doc->documentLabel, $doc->dueDate ?? '—',
                            (float) $doc->originalAmount, (float) $doc->balance,
                            $result->bucketLabels[$doc->bucket] ?? $doc->bucket,
                        ]));
                    }
                }
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
