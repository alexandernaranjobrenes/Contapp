<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Reports\ReportColumn;
use App\Domains\Inventory\Reports\ReportResult;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * UN exportador para todos los reportes de inventario, en vez de una clase
 * por reporte.
 *
 * Es posible porque todos devuelven la misma estructura (ReportResult), y es
 * deseable porque con una clase por reporte cada mejora del formato —anchos,
 * pie, encabezado congelado— habría que repetirla ocho veces y alguna se
 * quedaría atrás. Cuando eso pasa, dos exportaciones del mismo sistema se
 * ven distintas sin ninguna razón.
 *
 * Los números salen como NÚMEROS y no como texto: un XLSX donde no se puede
 * sumar una columna no sirve para lo que la gente abre un XLSX.
 */
class InventoryReportExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, ReportResult $result): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName($this->sheetName($header->title));

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

        // Las notas van ARRIBA y no al pie: explican cómo leer los números, y
        // al pie de 600 filas no las lee nadie.
        foreach ($result->notes as $note) {
            $writer->addRow(Row::fromValues([$note], $muted));
        }

        if ($result->notes !== []) {
            $writer->addRow(Row::fromValues([]));
        }

        $writer->addRow(Row::fromValues(
            array_map(fn (ReportColumn $c) => $c->label, $result->columns),
            $tableHeader
        ));

        foreach ($result->rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                fn (ReportColumn $c) => $this->cellValue($c, $row[$c->key] ?? null),
                $result->columns
            )));
        }

        $totals = $result->computedTotals();

        if ($totals !== [] && ! $result->isEmpty()) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_map(
                function (ReportColumn $c) use ($totals, $result) {
                    if (array_key_exists($c->key, $totals)) {
                        return $this->cellValue($c, $totals[$c->key]);
                    }

                    // La etiqueta "Total" va en la primera columna, que es
                    // donde el ojo la busca.
                    return $c === $result->columns[0] ? 'Total' : '';
                },
                $result->columns
            ), $bold));
        }

        if ($result->isEmpty()) {
            $writer->addRow(Row::fromValues(['No hay datos para los filtros seleccionados.'], $muted));
        }

        $writer->close();
    }

    /**
     * Numérico va como número para que Excel pueda sumarlo; el resto, texto.
     */
    private function cellValue(ReportColumn $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $column->isNumeric() ? (float) $value : (string) $value;
    }

    /**
     * Excel no admite más de 31 caracteres ni : \ / ? * [ ] en el nombre de
     * una hoja, y con cualquiera de esos el archivo no abre.
     */
    private function sheetName(string $title): string
    {
        $clean = str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $title);

        return mb_substr($clean, 0, 31);
    }
}
