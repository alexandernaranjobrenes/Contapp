<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exportación a XLSX de UN asiento (botón "Exportar" de
 * JournalEntries/Presentation.vue) — misma fuente de datos que la pantalla,
 * sin lógica de negocio propia (CLAUDE.md #4: separar obtención de datos de
 * renderizado). Mismo encabezado de identidad de empresa (logo, cédula
 * jurídica, dirección) que el resto de reportes (ReportHeaderFactory,
 * CLAUDE.md secc. 6) — antes este exportador no lo traía, quedaba en blanco
 * a diferencia de Balance General/Estado de Resultados/etc.
 */
class JournalEntryExporter
{
    public function writeTo(string $outputPath, ReportHeader $header, JournalEntry $entry): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Asiento');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $muted = (new Style())->setFontSize(9);
        $headerRow = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $bold = (new Style())->setFontBold();

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
            Row::fromValues(['Descripción', $entry->description]),
            Row::fromValues(['Fecha de contabilización', $entry->posting_date->format('Y-m-d')]),
            Row::fromValues(['Fecha de documento', $entry->document_date->format('Y-m-d')]),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues(
            ['Cuenta / socio', 'Centro de costo', 'Moneda', 'Débito', 'Crédito', 'Descripción'],
            $headerRow
        ));

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($entry->details as $detail) {
            $owner = $detail->businessPartner
                ? "{$detail->businessPartner->code} — {$detail->businessPartner->name}"
                : "{$detail->account?->code} — {$detail->account?->description_es}";

            $writer->addRow(Row::fromValues([
                $owner,
                $detail->costCenter ? "{$detail->costCenter->code} — {$detail->costCenter->name}" : '',
                $detail->currency?->code,
                (float) $detail->debit_local,
                (float) $detail->credit_local,
                $detail->description,
            ]));

            $totalDebit += (float) $detail->debit_local;
            $totalCredit += (float) $detail->credit_local;
        }

        $writer->addRow(Row::fromValues(['Total', '', '', $totalDebit, $totalCredit, ''], $bold));

        $writer->close();
    }
}
