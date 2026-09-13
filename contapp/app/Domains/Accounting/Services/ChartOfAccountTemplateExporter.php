<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera la plantilla XLSX de carga masiva del catálogo de cuentas: si la
 * compañía ya tiene cuentas, las exporta tal cual (para editarlas y
 * resubirlas); si está vacía, deja un par de filas de ejemplo. Las
 * columnas coinciden 1:1 con las que espera ChartOfAccountBulkImporter.
 */
class ChartOfAccountTemplateExporter
{
    public const HEADERS = [
        'codigo', 'nombre', 'nombre_en', 'tipo', 'moneda', 'iva',
        'cuenta_hoja', 'exige_socio', 'cuenta_monetaria', 'exige_centro_costo', 'activa',
    ];

    /**
     * Escribe el workbook en $outputPath (puede ser una ruta real o el
     * stream especial "php://output" cuando se llama desde dentro de un
     * callback de response()->streamDownload()).
     */
    public function writeTo(string $outputPath, Company $company): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        $this->writeCatalogSheet($writer, $company);

        $writer->addNewSheetAndMakeItCurrent();
        $this->writeInstructionsSheet($writer);

        $writer->close();
    }

    private function writeCatalogSheet(Writer $writer, Company $company): void
    {
        $writer->getCurrentSheet()->setName('Catálogo');

        $headerStyle = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle));

        $accounts = ChartOfAccount::where('company_id', $company->id)->orderBy('code')->get();

        if ($accounts->isEmpty()) {
            $writer->addRows([
                Row::fromValues([
                    '1-01-01-01-001', 'Caja general', 'Petty cash', 'Activos', 'Local', 'Ninguno',
                    'Sí', 'No', 'Sí', 'No', 'Sí',
                ]),
                Row::fromValues([
                    '1-01-02-01-001', 'Cuentas por cobrar clientes', 'Accounts receivable', 'Activos', 'Local', 'Ninguno',
                    'Sí', 'Sí', 'No', 'No', 'Sí',
                ]),
            ]);

            return;
        }

        foreach ($accounts as $account) {
            $writer->addRow(Row::fromValues([
                $account->code,
                $account->description_es,
                $account->description_en,
                ChartOfAccount::ACCOUNT_TYPES[$account->account_type] ?? $account->account_type,
                ChartOfAccount::CURRENCY_MODES[$account->currency_mode] ?? $account->currency_mode,
                ChartOfAccount::TAX_CLASSIFICATIONS[$account->tax_classification] ?? $account->tax_classification,
                $account->accepts_posting ? 'Sí' : 'No',
                $account->requires_business_partner ? 'Sí' : 'No',
                $account->is_cash_account ? 'Sí' : 'No',
                $account->requires_cost_center ? 'Sí' : 'No',
                $account->is_active ? 'Sí' : 'No',
            ]));
        }
    }

    private function writeInstructionsSheet(Writer $writer): void
    {
        $writer->getCurrentSheet()->setName('Instrucciones');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();

        $writer->addRows([
            Row::fromValues(['Cómo llenar el catálogo de cuentas'], $title),
            Row::fromValues([]),
            Row::fromValues(['Completá una fila por cuenta en la hoja "Catálogo". Si el código ya existe']),
            Row::fromValues(['en la compañía, esa cuenta se actualiza; si no existe, se crea. La carga']),
            Row::fromValues(['nunca elimina cuentas. Si alguna fila tiene un error, no se importa nada']),
            Row::fromValues(['hasta que corrijas el archivo y lo subas de nuevo.']),
            Row::fromValues([]),
            Row::fromValues(['Columna', 'Obligatorio', 'Valores permitidos'], $bold),
            Row::fromValues(['codigo', 'Sí', 'Texto, máx. 20 caracteres. Ej: 1-01-01-01-001']),
            Row::fromValues(['nombre', 'Sí', 'Texto, máx. 255 caracteres']),
            Row::fromValues(['nombre_en', 'No', 'Texto, máx. 255 caracteres']),
            Row::fromValues(['tipo', 'Sí', implode(' / ', ChartOfAccount::ACCOUNT_TYPES)]),
            Row::fromValues(['moneda', 'Sí', implode(' / ', ChartOfAccount::CURRENCY_MODES)]),
            Row::fromValues(['iva', 'Sí', implode(' / ', ChartOfAccount::TAX_CLASSIFICATIONS)]),
            Row::fromValues(['cuenta_hoja', 'No (Sí por defecto)', 'Sí / No — si acepta movimientos contables directos']),
            Row::fromValues(['exige_socio', 'No (No por defecto)', 'Sí / No — exige cliente o proveedor en cada línea']),
            Row::fromValues(['cuenta_monetaria', 'No (No por defecto)', 'Sí / No — elegible para asociar a una cuenta bancaria']),
            Row::fromValues(['exige_centro_costo', 'No (No por defecto)', 'Sí / No — reservado, todavía no bloquea el registro']),
            Row::fromValues(['activa', 'No (Sí por defecto)', 'Sí / No']),
            Row::fromValues([]),
            Row::fromValues(['La naturaleza (débito/crédito) de cada cuenta se calcula sola a partir']),
            Row::fromValues(['del tipo, igual que al crearla manualmente; no es una columna del archivo.']),
        ]);
    }
}
