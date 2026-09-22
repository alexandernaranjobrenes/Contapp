<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Tax\Models\TaxRate;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Plantilla XLSX de carga masiva de artículos. Si la compañía ya tiene
 * catálogo lo exporta tal cual —para editarlo y resubirlo, que es como se
 * corrige en masa—; si está vacío deja filas de ejemplo. Las columnas
 * coinciden 1:1 con las que espera ItemBulkImporter.
 *
 * Las hojas de apoyo (grupos, unidades, impuestos, catálogos de Hacienda) van
 * en el mismo archivo a propósito: el importador resuelve por CÓDIGO y sin
 * tenerlos a mano habría que adivinarlos o volver al sistema a copiarlos uno
 * por uno.
 */
class ItemTemplateExporter
{
    public const HEADERS = [
        'codigo', 'nombre', 'grupo', 'unidad', 'codigo_barras',
        'es_inventario', 'es_venta', 'es_compra', 'lleva_lotes',
        'minimo', 'maximo',
        'cabys', 'unidad_hacienda', 'tarifa_iva', 'impuesto',
        'activo',
    ];

    public function writeTo(string $outputPath, Company $company): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        $this->writeItemsSheet($writer, $company);

        $writer->addNewSheetAndMakeItCurrent();
        $this->writeReferenceSheet($writer, $company);

        $writer->addNewSheetAndMakeItCurrent();
        $this->writeInstructionsSheet($writer);

        $writer->close();
    }

    private function writeItemsSheet(Writer $writer, Company $company): void
    {
        $writer->getCurrentSheet()->setName('Artículos');

        $headerStyle = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle));

        $items = Item::with(['itemGroup:id,code', 'unitOfMeasure:id,code'])
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        if ($items->isEmpty()) {
            $writer->addRows([
                Row::fromValues([
                    'ART-001', 'Tornillo hexagonal 1/4"', 'FERR', 'Unid', '7501234567890',
                    'Sí', 'Sí', 'Sí', 'No',
                    10, 50,
                    '2310110000000', 'Unid', '08', 'IVA-13',
                    'Sí',
                ]),
                Row::fromValues([
                    'SERV-001', 'Instalación a domicilio', '', 'Sp', '',
                    'No', 'Sí', 'No', 'No',
                    '', '',
                    '8511010000000', 'Sp', '08', 'IVA-13',
                    'Sí',
                ]),
            ]);

            return;
        }

        foreach ($items as $item) {
            $writer->addRow(Row::fromValues([
                $item->code,
                $item->name,
                $item->itemGroup?->code ?? '',
                $item->unitOfMeasure?->code ?? '',
                $item->barcode ?? '',
                $this->label($item->is_inventory_item),
                $this->label($item->is_sales_item),
                $this->label($item->is_purchase_item),
                $this->label($item->tracks_lots),
                (float) $item->minimum_stock,
                $item->maximum_stock !== null ? (float) $item->maximum_stock : '',
                $item->cabys_code ?? '',
                $item->fiscal_unit_code ?? '',
                $item->iva_rate_code ?? '',
                $item->taxRate?->code ?? '',
                $item->status === 'active' ? 'Sí' : 'No',
            ]));
        }
    }

    /**
     * Los códigos válidos de cada columna que se resuelve por código. Sin
     * esta hoja el archivo es inutilizable fuera del sistema.
     */
    private function writeReferenceSheet(Writer $writer, Company $company): void
    {
        $writer->getCurrentSheet()->setName('Códigos válidos');

        $bold = (new Style())->setFontBold();
        $tableHeader = (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRow(Row::fromValues(['Grupos de artículos (columna "grupo")'], $bold));
        $writer->addRow(Row::fromValues(['Código', 'Nombre'], $tableHeader));
        foreach (ItemGroup::where('company_id', $company->id)->orderBy('code')->get() as $group) {
            $writer->addRow(Row::fromValues([$group->code, $group->name]));
        }

        $writer->addRows([Row::fromValues([]), Row::fromValues(['Unidades de medida (columna "unidad")'], $bold)]);
        $writer->addRow(Row::fromValues(['Código', 'Nombre'], $tableHeader));
        foreach (UnitOfMeasure::where('company_id', $company->id)->orderBy('code')->get() as $uom) {
            $writer->addRow(Row::fromValues([$uom->code, $uom->name]));
        }

        $writer->addRows([Row::fromValues([]), Row::fromValues(['Indicadores de impuesto (columna "impuesto")'], $bold)]);
        $writer->addRow(Row::fromValues(['Código', 'Nombre', 'Porcentaje'], $tableHeader));
        foreach (TaxRate::orderBy('code')->get() as $rate) {
            $writer->addRow(Row::fromValues([$rate->code, $rate->name, (float) $rate->percentage]));
        }

        $writer->addRows([Row::fromValues([]), Row::fromValues(['Tarifas de IVA de Hacienda (columna "tarifa_iva")'], $bold)]);
        $writer->addRow(Row::fromValues(['Código', 'Descripción', 'Porcentaje'], $tableHeader));
        foreach (FiscalCatalogs::IVA_RATES as $code => $rate) {
            $writer->addRow(Row::fromValues([$code, $rate['label'], (float) $rate['percentage']]));
        }

        $writer->addRows([Row::fromValues([]), Row::fromValues(['Unidades de Hacienda (columna "unidad_hacienda")'], $bold)]);
        $writer->addRow(Row::fromValues(['Código', 'Descripción'], $tableHeader));
        foreach (FiscalCatalogs::UNITS as $code => $label) {
            $writer->addRow(Row::fromValues([$code, $label]));
        }
    }

    private function writeInstructionsSheet(Writer $writer): void
    {
        $writer->getCurrentSheet()->setName('Instrucciones');

        $bold = (new Style())->setFontBold();
        $warning = (new Style())->setFontBold()->setFontColor('A04000');

        $writer->addRows([
            Row::fromValues(['Carga masiva de artículos'], (new Style())->setFontBold()->setFontSize(14)),
            Row::fromValues([]),
            Row::fromValues(['Lo que NO se puede cargar por acá, y por qué'], $warning),
            Row::fromValues(['Existencias y costo promedio NO son columnas de este archivo.']),
            Row::fromValues(['El costo lo mantiene exclusivamente el motor de movimientos, porque cada cambio de costo']),
            Row::fromValues(['tiene que generar su asiento. Un artículo importado con existencia sería inventario sin']),
            Row::fromValues(['contrapartida contable: la bodega diría una cosa y el balance otra.']),
            Row::fromValues(['Las existencias iniciales se cargan con una entrada de mercancía contra la cuenta de']),
            Row::fromValues(['apertura, que sí genera el asiento.']),
            Row::fromValues([]),
            Row::fromValues(['Cómo funciona'], $bold),
            Row::fromValues(['- Todo o nada: si una sola fila tiene un error, no se importa ninguna.']),
            Row::fromValues(['  Así nunca queda un catálogo a medio cargar que haya que reconciliar a mano.']),
            Row::fromValues(['- Un código que ya existe en la compañía se ACTUALIZA; uno nuevo se crea.']),
            Row::fromValues(['- Las columnas de Sí/No aceptan Sí, No, S, N, 1, 0, X, o quedar vacías (toman su valor por defecto).']),
            Row::fromValues(['- "grupo", "unidad" e "impuesto" se resuelven por CÓDIGO. Ver la hoja "Códigos válidos".']),
            Row::fromValues([]),
            Row::fromValues(['Reglas que el archivo tiene que respetar'], $bold),
            Row::fromValues(['- El código CAByS, si viene, son exactamente 13 dígitos.']),
            Row::fromValues(['- "impuesto" y "tarifa_iva" tienen que valer el mismo porcentaje: el primero es con lo que se']),
            Row::fromValues(['  contabiliza y el segundo con lo que se declara ante Hacienda. Si no coinciden, el XML declara']),
            Row::fromValues(['  un porcentaje y el asiento registra otro.']),
            Row::fromValues(['- Un servicio (es_inventario = No) no lleva lotes ni niveles de reposición: no hay existencia.']),
            Row::fromValues(['- Un artículo con existencia NO se puede convertir en servicio. Hay que dejarlo en cero primero.']),
            Row::fromValues(['- "maximo" no puede ser menor que "minimo": la sugerencia de compra quedaría en cero y el']),
            Row::fromValues(['  artículo no se repondría nunca.']),
        ]);
    }

    private function label(bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }
}
