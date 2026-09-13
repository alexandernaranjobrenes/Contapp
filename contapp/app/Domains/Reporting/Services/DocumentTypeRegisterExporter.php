<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Models\DocumentType;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Registro de todos los asientos de UN tipo de documento (ej. "todas las
 * facturas de venta", "todos los diarios"), una fila por LÍNEA de detalle —
 * no por asiento — para que sirva como insumo de auxiliares/libros fiscales
 * (ej. libro de compras/ventas), donde lo que importa es el desglose por
 * cuenta/socio de cada línea, no solo el total del asiento. $documentType
 * null = "Todos los tipos de documento" (sin filtrar por tipo).
 *
 * $documentType, cuando viene, ya llega resuelto por el controlador con el
 * CompanyScope ambiental activo (DocumentType::findOrFail() dentro de una
 * request autenticada) — este exportador no vuelve a validar la compañía;
 * en modo "todos" el filtro de compañía lo sigue dando el CompanyScope de
 * JournalEntry (nunca se quita explícitamente).
 */
class DocumentTypeRegisterExporter
{
    /**
     * @param  string[]  $statuses  subconjunto de ['draft', 'posted', 'voided']
     */
    public function writeTo(string $outputPath, ?DocumentType $documentType, ?string $from, ?string $to, array $statuses): void
    {
        $statusLabels = ['draft' => 'Preliminar', 'posted' => 'Contabilizado', 'voided' => 'Anulado'];

        $entries = JournalEntry::query()
            ->when($documentType, fn ($q) => $q->where('document_type_id', $documentType->id))
            ->whereIn('status', $statuses)
            ->when($from, fn ($q) => $q->where('posting_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('posting_date', '<=', $to))
            ->with([
                'documentType:id,code,name',
                'numberSeries:id,name,holder_name',
                'businessPartner:id,code,name',
                'details.account:id,code,description_es',
                'details.businessPartner:id,code,name',
                'details.costCenter:id,code,name',
                'details.currency:id,code',
            ])
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();

        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Registro');

        $bold = (new Style())->setFontBold();
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues([$documentType ? "{$documentType->code} — {$documentType->name}" : 'Todos los tipos de documento'], $bold),
            Row::fromValues([]),
        ]);

        $writer->addRow(Row::fromValues([
            'Tipo de documento', 'Documento', 'Serie', 'Fecha de contabilización', 'Estado', 'Descripción del asiento',
            // Documento de referencia y su fecha son por LÍNEA, no por
            // asiento: un mismo asiento puede juntar varias facturas con
            // números y fechas distintos (ver JournalDetail::reference_document).
            'Documento de referencia', 'Fecha de documento',
            'Cuenta', 'Descripción de línea', 'Socio de negocio', 'Centro de costo', 'Moneda',
            'Débito', 'Crédito',
        ], $tableHeader));

        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($entries as $entry) {
            $entryDocumentType = $entry->documentType;

            $documentLabel = $entry->document_number
                ? "{$entryDocumentType->code}-{$entry->document_number}"
                : "{$entryDocumentType->code} — sin número aún";

            $seriesLabel = $entry->numberSeries
                ? $entry->numberSeries->name.($entry->numberSeries->holder_name ? " ({$entry->numberSeries->holder_name})" : '').' #'.$entry->series_number
                : '—';

            foreach ($entry->details as $detail) {
                $totalDebit = bcadd($totalDebit, (string) $detail->debit_local, 2);
                $totalCredit = bcadd($totalCredit, (string) $detail->credit_local, 2);

                $businessPartner = $detail->businessPartner ?? $entry->businessPartner;

                $writer->addRow(Row::fromValues([
                    "{$entryDocumentType->code} — {$entryDocumentType->name}",
                    $documentLabel,
                    $seriesLabel,
                    (string) $entry->posting_date,
                    $statusLabels[$entry->status] ?? $entry->status,
                    $entry->description ?? '—',
                    $detail->reference_document ?? '—',
                    $detail->reference_document_date ? (string) $detail->reference_document_date : '—',
                    $detail->account ? "{$detail->account->code} — {$detail->account->description_es}" : '—',
                    $detail->description ?? '—',
                    $businessPartner ? "{$businessPartner->code} — {$businessPartner->name}" : '—',
                    $detail->costCenter ? "{$detail->costCenter->code} — {$detail->costCenter->name}" : '—',
                    $detail->currency?->code ?? '—',
                    (float) $detail->debit_local,
                    (float) $detail->credit_local,
                ]));
            }
        }

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues(['', '', '', '', '', '', '', '', '', '', '', '', 'Totales', (float) $totalDebit, (float) $totalCredit], $bold),
        ]);

        $writer->close();
    }
}
