<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\DataTransferObjects\BankReconciliationReportRow;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class BankReconciliationReportExporter
{
    /**
     * @param  BankReconciliationReportRow[]  $rows
     */
    public function writeTo(string $outputPath, ReportHeader $header, BankAccount $bankAccount, array $rows): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Conciliaciones bancarias');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $muted = (new Style())->setFontSize(9);
        $sectionTitle = (new Style())->setFontBold()->setFontSize(11);
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $lineHeader = (new Style())->setFontBold()->setBackgroundColor('E9EDF5');
        $balanced = (new Style())->setFontColor('1B7F3A');
        $unbalanced = (new Style())->setFontColor('B00020');
        $pending = (new Style())->setBackgroundColor('FFF3CD');

        $writer->addRows([
            Row::fromValues([$header->companyName], $title),
            Row::fromValues(['Cédula jurídica: '.($header->taxId ?? '—'), 'Dirección: '.($header->address ?? '—')], $muted),
            Row::fromValues([$header->title], $bold),
            Row::fromValues(["Cuenta: {$bankAccount->bank_name} — {$bankAccount->account_number}"], $muted),
            Row::fromValues([$header->paramsSummary], $muted),
            Row::fromValues(['Generado por '.$header->generatedByName.' el '.$header->generatedAt->format('Y-m-d H:i')], $muted),
            Row::fromValues([]),
            Row::fromValues(['Resumen'], $sectionTitle),
            Row::fromValues([
                'Corte', 'Estado',
                'Saldo banco', 'Depósitos no acreditados', 'Cheques no pagados', 'Saldo banco ajustado',
                'Saldo libros', 'Créd. banco no reg.', 'Déb. banco no reg.', 'Saldo libros ajustado',
                'Cuadra', 'Generada por',
            ], $tableHeader),
        ]);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([
                $row->cutoffDate, $row->statusLabel,
                (float) $row->bankBalance, (float) $row->unrecordedDeposits, (float) $row->unpaidChecks, (float) $row->adjustedBankBalance,
                (float) $row->bookBalance, (float) $row->unrecordedBankCredits, (float) $row->unrecordedBankDebits, (float) $row->adjustedBookBalance,
                $row->isBalanced ? 'Sí' : 'No', $row->createdByName ?? '—',
            ], $row->isBalanced ? $balanced : $unbalanced));
        }

        if (empty($rows)) {
            $writer->addRow(Row::fromValues(['Sin conciliaciones en el período seleccionado.']));
        }

        // Detalle línea por línea de cada conciliación — resaltado en
        // amarillo lo que sigue pendiente de confirmar contra el banco, que
        // es exactamente lo que compone "depósitos no acreditados" (tipo
        // depósito) y "cheques no pagados" (tipo cheque) del resumen arriba.
        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues(['Detalle de movimientos por conciliación'], $sectionTitle),
            Row::fromValues(['Resaltado en amarillo: pendiente de confirmar contra el banco (depósito en tránsito / cheque no pagado).'], $muted),
        ]);

        foreach ($rows as $row) {
            $writer->addRows([
                Row::fromValues([]),
                Row::fromValues(["Corte {$row->cutoffDate} — {$row->statusLabel}"], $bold),
                Row::fromValues([
                    'Fecha', 'Documento', 'Documento de referencia', 'Fecha de documento', 'Descripción',
                    'Débito', 'Crédito', 'Tipo', 'Confirmado en banco',
                ], $lineHeader),
            ]);

            foreach ($row->lines as $line) {
                $writer->addRow(Row::fromValues([
                    $line->date, $line->document, $line->referenceDocument ?? '—', $line->referenceDocumentDate ?? '—', $line->description,
                    (float) $line->debit, (float) $line->credit,
                    $line->type === 'deposito' ? 'Depósito' : 'Cheque',
                    $line->matchedInBank ? 'Sí' : 'No',
                ], $line->matchedInBank ? null : $pending));
            }

            if (empty($row->lines)) {
                $writer->addRow(Row::fromValues(['Sin movimientos.']));
            }
        }

        $writer->close();
    }
}
