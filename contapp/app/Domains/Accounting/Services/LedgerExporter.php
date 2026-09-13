<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\LedgerResult;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class LedgerExporter
{
    public function writeTo(string $outputPath, LedgerResult $ledger): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Mayor');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();
        $header = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues(["{$ledger->ownerCode} — {$ledger->ownerName}"], $title),
            Row::fromValues(['Del: '.($ledger->from ?? 'inicio'), 'Al: '.($ledger->to ?? 'hoy')]),
            Row::fromValues([]),
            Row::fromValues(['Saldo inicial', $ledger->openingBalance], $bold),
        ]);

        $writer->addRow(Row::fromValues(['Fecha', 'Documento', 'Descripción', 'Débito', 'Crédito', 'Saldo'], $header));

        foreach ($ledger->movements as $movement) {
            $writer->addRow(Row::fromValues([
                $movement->date, $movement->document, $movement->description,
                (float) $movement->debit, (float) $movement->credit, (float) $movement->balance,
            ]));
        }

        $writer->addRows([
            Row::fromValues([]),
            Row::fromValues(['Saldo final', $ledger->closingBalance], $bold),
        ]);

        $writer->close();
    }
}
