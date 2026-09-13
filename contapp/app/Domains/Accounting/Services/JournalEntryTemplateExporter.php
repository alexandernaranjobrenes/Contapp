<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera la plantilla XLSX para importar UN asiento completo (encabezado +
 * líneas) desde Excel. A diferencia de la plantilla del catálogo de cuentas,
 * acá no hay "datos actuales de la compañía" que exportar: siempre es un
 * ejemplo ilustrativo en blanco. Las columnas coinciden 1:1 con las que
 * espera JournalEntryBulkImporter.
 */
class JournalEntryTemplateExporter
{
    public const HEADERS = [
        'tipo_documento', 'fecha', 'descripcion_asiento',
        'cuenta', 'socio', 'moneda', 'debito', 'credito', 'descripcion_linea', 'norma_reparto',
        'documento_referencia', 'fecha_documento_referencia',
    ];

    public function writeTo(string $outputPath, Company $company): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        $this->writeEntrySheet($writer, $company);

        $writer->addNewSheetAndMakeItCurrent();
        $this->writeInstructionsSheet($writer, $company);

        $writer->close();
    }

    private function writeEntrySheet(Writer $writer, Company $company): void
    {
        $writer->getCurrentSheet()->setName('Asiento');

        $headerStyle = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle));

        $localCode = $company->localCurrency?->code ?? 'CRC';

        // Ejemplo: aporte de capital, dos líneas del mismo asiento. El tipo
        // de documento/fecha/descripción del asiento solo se leen de la
        // PRIMERA fila con datos — en las siguientes se dejan en blanco.
        $writer->addRows([
            Row::fromValues(['ADD', now()->format('Y-m-d'), 'Aporte de capital', '1-01-01-01-001', '', $localCode, '500', '0', '', '', 'Depósito-001', now()->format('Y-m-d')]),
            Row::fromValues(['', '', '', '3-01-01-01-001', '', $localCode, '0', '500', '', '', '', '']),
        ]);
    }

    private function writeInstructionsSheet(Writer $writer, Company $company): void
    {
        $writer->getCurrentSheet()->setName('Instrucciones');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();

        $writer->addRows([
            Row::fromValues(['Cómo importar un asiento desde Excel'], $title),
            Row::fromValues([]),
            Row::fromValues(['Cada archivo representa UN solo asiento: una fila por línea del asiento']),
            Row::fromValues(['en la hoja "Asiento". El tipo de documento, la fecha y la descripción']),
            Row::fromValues(['del asiento solo hace falta llenarlos en la PRIMERA fila con datos; se']),
            Row::fromValues(['pueden dejar en blanco en las siguientes líneas.']),
            Row::fromValues([]),
            Row::fromValues(['El asiento importado queda como PRELIMINAR (borrador): si falta algún']),
            Row::fromValues(['dato o no cuadra todavía, igual se guarda para poder revisarlo y']),
            Row::fromValues(['corregirlo en pantalla antes de contabilizarlo formalmente. Lo único']),
            Row::fromValues(['que sí tiene que estar bien desde el archivo es que cada código (cuenta,']),
            Row::fromValues(['socio, moneda, norma de reparto) exista de verdad en la compañía.']),
            Row::fromValues([]),
            Row::fromValues(['Columna', 'Obligatorio', 'Valores permitidos'], $bold),
            Row::fromValues(['tipo_documento', 'Sí (primera fila)', 'Código de un tipo de documento activo — ver lista abajo']),
            Row::fromValues(['fecha', 'Sí (primera fila)', 'Fecha en formato AAAA-MM-DD']),
            Row::fromValues(['descripcion_asiento', 'No', 'Texto libre, máx. 255 caracteres']),
            Row::fromValues(['cuenta', 'Sí, salvo que la línea traiga "socio"', 'Código de una cuenta del catálogo']),
            Row::fromValues(['socio', 'Sí, salvo que la línea traiga "cuenta"', 'Código de un socio de negocio (cliente/proveedor)']),
            Row::fromValues(['moneda', 'Sí', 'Código de moneda, ej. CRC o USD']),
            Row::fromValues(['debito', 'Sí', 'Número igual o mayor a 0']),
            Row::fromValues(['credito', 'Sí', 'Número igual o mayor a 0 (una línea no puede tener débito y crédito a la vez)']),
            Row::fromValues(['descripcion_linea', 'No', 'Texto libre, máx. 255 caracteres']),
            Row::fromValues(['norma_reparto', 'Sí, si la cuenta exige norma de reparto', 'Código de una norma de reparto de la compañía']),
            Row::fromValues(['documento_referencia', 'No', 'Texto libre, ej. número de factura del proveedor — por LÍNEA, no por asiento']),
            Row::fromValues(['fecha_documento_referencia', 'No', 'Fecha en formato AAAA-MM-DD — la fecha de ESE documento, no la de contabilización']),
            Row::fromValues([]),
            Row::fromValues(['Una línea con "socio" no necesita "cuenta": se usa automáticamente la']),
            Row::fromValues(['cuenta contable de control configurada en ese socio de negocio.']),
            Row::fromValues([]),
            Row::fromValues(['Un mismo asiento puede juntar varias facturas de distintos proveedores']),
            Row::fromValues(['(o del mismo proveedor en fechas distintas): "documento_referencia" y']),
            Row::fromValues(['"fecha_documento_referencia" son por LÍNEA, así que cada una puede traer']),
            Row::fromValues(['su propio número y fecha, sin importar la fecha de contabilización del']),
            Row::fromValues(['encabezado.']),
            Row::fromValues([]),
            Row::fromValues(['Tipos de documento activos de esta compañía'], $bold),
            Row::fromValues(['Código', 'Nombre'], $bold),
        ]);

        $documentTypes = DocumentType::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('generates_journal', true)
            ->orderBy('code')
            ->get(['code', 'name']);

        foreach ($documentTypes as $documentType) {
            $writer->addRow(Row::fromValues([$documentType->code, $documentType->name]));
        }
    }
}
