<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Misma fuente de datos que la pantalla y que el PDF
 * (ReorderSuggestionService), como pide el checklist de CLAUDE.md secc. 9.
 *
 * Este archivo se saca para negociar con el proveedor o para autorizar la
 * compra, así que las tres columnas que forman el disponible van explícitas y
 * no solo su resultado: quien lo revise tiene que poder ver POR QUÉ el
 * sistema pide comprar algo de lo que hay existencia —está apartado— o por
 * qué no pide nada de lo que está en cero —ya viene en camino—.
 */
class ReorderSuggestionExporter
{
    /**
     * @param  Collection<int, array<string, mixed>>  $suggestions
     */
    public function writeTo(string $outputPath, ReportHeader $header, Collection $suggestions): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Sugerencia de compra');

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
            Row::fromValues(['Disponible = existencia − apartado + en camino'], $muted),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues([
            'Artículo', 'Descripción', 'Grupo', 'Almacén', 'U/M',
            'Existencia', 'Apartado', 'En camino', 'Disponible',
            'Mínimo', 'Máximo', 'Nivel', 'Sugerido', 'Costo prom.', 'Costo estimado', 'Comprable',
        ], $tableHeader));

        foreach ($suggestions as $s) {
            $writer->addRow(Row::fromValues([
                $s['item_code'],
                $s['item_name'],
                $s['item_group'] ?? '—',
                $s['warehouse_code'],
                $s['uom'] ?? '—',
                (float) $s['on_hand'],
                (float) $s['reserved'],
                (float) $s['ordered'],
                (float) $s['available'],
                (float) $s['minimum_stock'],
                $s['maximum_stock'] !== null ? (float) $s['maximum_stock'] : '—',
                // Saber si el nivel salió de la ficha o del almacén es lo que
                // permite corregirlo en el lugar correcto cuando la
                // sugerencia se ve mal.
                $s['minimum_is_override'] ? 'Del almacén' : 'De la ficha',
                (float) $s['suggested_quantity'],
                (float) $s['avg_cost_local'],
                (float) $s['estimated_cost'],
                // Un artículo que se fabrica aparece igual —está bajo mínimo
                // y hay que hacer algo— pero no va en una orden de compra.
                $s['is_purchase_item'] ? 'Sí' : 'No: se fabrica',
            ]));
        }

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues([
                '', '', '', '', '', '', '', '', '', '', '', '',
                'Total estimado',
                '',
                (float) $suggestions->sum(fn (array $s) => (float) $s['estimated_cost']),
            ], $bold),
        ]);

        $writer->close();
    }
}
