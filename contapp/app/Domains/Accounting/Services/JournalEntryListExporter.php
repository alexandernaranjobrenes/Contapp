<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * "Registro de asientos" — el Libro diario del catálogo de reportes
 * (CLAUDE.md secc. 5): un asiento por fila (no el detalle de sus líneas,
 * para eso está el Registro por tipo de documento / DocumentTypeRegisterExporter),
 * con el total de débito/crédito de cada uno para verificar de un vistazo
 * que cuadra. Botón "Exportar XLSX" de JournalEntries/Index.vue.
 */
class JournalEntryListExporter
{
    private const STATUS_LABELS = ['draft' => 'Preliminar', 'posted' => 'Contabilizado', 'voided' => 'Anulado'];

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    public function writeTo(string $outputPath, ReportHeader $header, Collection $entries): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Registro de asientos');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $totalStyle = (new Style())->setFontBold()->setBackgroundColor('F2F2F2');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues(
            ['Documento', 'Serie', 'Fecha', 'Descripción', 'Estado', 'Débito', 'Crédito'],
            $tableHeader
        ));

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($entries as $entry) {
            $label = $entry->document_number
                ? "{$entry->documentType->code}-{$entry->document_number}"
                : "{$entry->documentType->code} — preliminar";

            $series = $entry->numberSeries
                ? "{$entry->numberSeries->name} #{$entry->series_number}"
                : '';

            $writer->addRow(Row::fromValues([
                $label,
                $series,
                $entry->posting_date->format('Y-m-d'),
                $entry->description,
                self::STATUS_LABELS[$entry->status] ?? $entry->status,
                (float) $entry->total_debit,
                (float) $entry->total_credit,
            ]));

            $totalDebit += (float) $entry->total_debit;
            $totalCredit += (float) $entry->total_credit;
        }

        $writer->addRow(Row::fromValues(['', '', '', '', 'Total', $totalDebit, $totalCredit], $totalStyle));

        $writer->close();
    }
}
