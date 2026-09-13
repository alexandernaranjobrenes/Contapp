<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera la plantilla XLSX para la carga de saldos iniciales (puesta en
 * marcha de una compañía que ya operaba fuera de este sistema): a partir del
 * catálogo de cuentas YA CREADO, deja una fila lista para llenar por cada
 * cuenta que acepta movimiento directo y no exige socio de negocio, más una
 * fila por cada socio de negocio activo (cubre las cuentas de control CxC/CxP,
 * que si exigen socio). El usuario solo tiene que escribir los montos.
 *
 * A diferencia de JournalEntryTemplateExporter, esta plantilla NO lleva
 * columnas de tipo de documento/fecha/descripción de encabezado: ese dato se
 * captura una sola vez en la pantalla de carga (OpeningBalance/Create.vue),
 * no por archivo — acá siempre es un único asiento de apertura, así que no
 * hace falta que el archivo lo declare fila por fila.
 */
class OpeningBalanceTemplateExporter
{
    public const HEADERS = [
        'cuenta', 'socio', 'moneda', 'debito', 'credito', 'norma_reparto', 'descripcion_linea',
    ];

    public function writeTo(string $outputPath, Company $company): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        $this->writeBalancesSheet($writer, $company);

        $writer->addNewSheetAndMakeItCurrent();
        $this->writeInstructionsSheet($writer);

        $writer->close();
    }

    private function writeBalancesSheet(Writer $writer, Company $company): void
    {
        $writer->getCurrentSheet()->setName('Saldos iniciales');

        $headerStyle = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle));

        $localCode = $company->localCurrency?->code ?? 'CRC';

        $accounts = ChartOfAccount::where('company_id', $company->id)
            ->where('accepts_posting', true)
            ->where('requires_business_partner', false)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code']);

        foreach ($accounts as $account) {
            $writer->addRow(Row::fromValues([$account->code, '', $localCode, '', '', '', '']));
        }

        $partners = BusinessPartner::where('company_id', $company->id)
            ->where('status', 'active')
            ->with('currency:id,code')
            ->orderBy('code')
            ->get(['id', 'code', 'currency_id']);

        foreach ($partners as $partner) {
            $writer->addRow(Row::fromValues(['', $partner->code, $partner->currency?->code ?? $localCode, '', '', '', '']));
        }
    }

    private function writeInstructionsSheet(Writer $writer): void
    {
        $writer->getCurrentSheet()->setName('Instrucciones');

        $title = (new Style())->setFontBold()->setFontSize(13);
        $bold = (new Style())->setFontBold();

        $writer->addRows([
            Row::fromValues(['Cómo cargar los saldos iniciales'], $title),
            Row::fromValues([]),
            Row::fromValues(['Esta plantilla ya trae una fila por cada cuenta del catálogo que recibe']),
            Row::fromValues(['movimiento directo, y una fila por cada socio de negocio activo (para las']),
            Row::fromValues(['cuentas de clientes/proveedores, que exigen socio). Solo hace falta llenar']),
            Row::fromValues(['el débito o el crédito de cada fila con saldo — dejá en 0 o en blanco las']),
            Row::fromValues(['que no apliquen. Se puede agregar filas nuevas si falta alguna cuenta o']),
            Row::fromValues(['socio, y se puede borrar las filas que no se necesiten.']),
            Row::fromValues([]),
            Row::fromValues(['La fecha y la descripción del asiento de apertura se escriben una sola']),
            Row::fromValues(['vez en la pantalla de carga, no en este archivo. Los débitos deben']),
            Row::fromValues(['cuadrar exactamente con los créditos: si no cuadra, no se carga nada.']),
            Row::fromValues([]),
            Row::fromValues(['Columna', 'Obligatorio', 'Valores permitidos'], $bold),
            Row::fromValues(['cuenta', 'Sí, salvo que la fila traiga "socio"', 'Código de una cuenta del catálogo']),
            Row::fromValues(['socio', 'Sí, salvo que la fila traiga "cuenta"', 'Código de un socio de negocio (cliente/proveedor)']),
            Row::fromValues(['moneda', 'Sí', 'Código de moneda, ej. CRC o USD']),
            Row::fromValues(['debito', 'Sí', 'Número igual o mayor a 0']),
            Row::fromValues(['credito', 'Sí', 'Número igual o mayor a 0 (una fila no puede tener débito y crédito a la vez)']),
            Row::fromValues(['norma_reparto', 'Sí, si la cuenta exige norma de reparto', 'Código de una norma de reparto de la compañía']),
            Row::fromValues(['descripcion_linea', 'No', 'Texto libre, máx. 255 caracteres']),
            Row::fromValues([]),
            Row::fromValues(['Cada fila con socio queda registrada como una partida pendiente (con el']),
            Row::fromValues(['saldo que traía de antes del sistema), lista para poder aplicarle cobros']),
            Row::fromValues(['o pagos más adelante — igual que cualquier factura contabilizada normal.']),
        ]);
    }
}
