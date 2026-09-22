<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Models\Company;
use App\Domains\Inventory\DataTransferObjects\ItemImportResult;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Support\ItemFiscalConsistency;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Carga masiva de artículos desde el XLSX que genera ItemTemplateExporter.
 *
 * Todo-o-nada, igual que el catálogo de cuentas (docs/decisiones.md
 * 2026-08-13): si una sola fila tiene un error no se importa ninguna. Con 800
 * artículos, una carga a medias deja al usuario reconciliando a mano qué
 * entró y qué no, que es peor que no haber empezado.
 *
 * ── Lo que este importador NO carga, que es la decisión importante ───────
 *
 * Existencias y costo promedio no son columnas del archivo. El costo lo
 * mantiene exclusivamente el motor de movimientos (Fase 2) porque cada cambio
 * de costo tiene que generar su asiento; un artículo importado con existencia
 * sería inventario sin contrapartida contable, y el kardex dejaría de cuadrar
 * con el mayor desde el primer día. Las existencias iniciales entran por una
 * entrada de mercancía contra la cuenta de apertura, que sí contabiliza.
 *
 * Es la misma frontera que ya respetan la ficha del artículo y la pantalla de
 * niveles: el catálogo describe QUÉ es cada artículo, nunca CUÁNTO hay.
 */
class ItemBulkImporter
{
    private const REQUIRED_HEADERS = ['codigo', 'nombre', 'unidad'];

    /** columna del archivo => [campo del modelo, valor por defecto si viene vacía] */
    private const BOOLEAN_COLUMNS = [
        'es_inventario' => ['field' => 'is_inventory_item', 'default' => true],
        'es_venta' => ['field' => 'is_sales_item', 'default' => true],
        'es_compra' => ['field' => 'is_purchase_item', 'default' => true],
        'lleva_lotes' => ['field' => 'tracks_lots', 'default' => false],
        'activo' => ['field' => 'status', 'default' => true],
    ];

    public function import(string $filePath, Company $company): ItemImportResult
    {
        $parsed = $this->readRows($filePath);

        if (isset($parsed['fatal'])) {
            return new ItemImportResult(0, 0, [$parsed['fatal']]);
        }

        $header = $parsed['header'];
        $missing = array_diff(self::REQUIRED_HEADERS, array_keys($header));

        if ($missing !== []) {
            return new ItemImportResult(0, 0, [
                'Al archivo le faltan estas columnas obligatorias: '.implode(', ', $missing).'.',
            ]);
        }

        // Los catálogos se resuelven una sola vez y no fila por fila: con 800
        // artículos serían 2.400 consultas.
        $groups = ItemGroup::where('company_id', $company->id)->pluck('id', 'code');
        $uoms = UnitOfMeasure::where('company_id', $company->id)->pluck('id', 'code');
        $taxRates = TaxRate::get(['id', 'code'])->pluck('id', 'code');
        $existing = Item::where('company_id', $company->id)->get(['id', 'code', 'is_inventory_item'])->keyBy('code');

        $errors = [];
        $seenCodes = [];
        $validRows = [];

        foreach ($parsed['rows'] as $lineNumber => $raw) {
            $data = $this->extractRow($raw, $header);

            if ($this->isBlankRow($data)) {
                continue;
            }

            $rowErrors = $this->validateRow($data, $lineNumber, $groups, $uoms, $taxRates, $existing, $company);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $code = trim((string) $data['codigo']);

            if (isset($seenCodes[$code])) {
                $errors[] = "Fila {$lineNumber}: el código {$code} está repetido en el archivo (ya aparece en la fila {$seenCodes[$code]}).";

                continue;
            }

            $seenCodes[$code] = $lineNumber;
            $validRows[$code] = $this->mapToAttributes($data, $groups, $uoms, $taxRates);
        }

        if ($errors !== []) {
            return new ItemImportResult(0, 0, $errors);
        }

        if ($validRows === []) {
            return new ItemImportResult(0, 0, ['El archivo no tiene filas con datos para importar.']);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($validRows, $company, $existing, &$created, &$updated): void {
            foreach ($validRows as $code => $attributes) {
                if (isset($existing[$code])) {
                    $updated++;
                } else {
                    $created++;
                }

                Item::updateOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    $attributes,
                );
            }
        });

        return new ItemImportResult($created, $updated, []);
    }

    /**
     * @return array{fatal?: string, header?: array<string,int>, rows?: array<int,array<int,mixed>>}
     */
    private function readRows(string $filePath): array
    {
        $reader = new Reader();

        try {
            $reader->open($filePath);
        } catch (Throwable) {
            return ['fatal' => 'No se pudo leer el archivo. Verificá que sea un .xlsx válido generado por la plantilla.'];
        }

        $header = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $lineNumber = 0;

            foreach ($sheet->getRowIterator() as $row) {
                $lineNumber++;
                $values = $row->toArray();

                if ($lineNumber === 1) {
                    foreach ($values as $index => $value) {
                        $key = strtolower(trim((string) $value));

                        if ($key !== '') {
                            $header[$key] = $index;
                        }
                    }

                    continue;
                }

                $rows[$lineNumber] = $values;
            }

            break; // solo la primera hoja; "Códigos válidos" e "Instrucciones" no se procesan
        }

        $reader->close();

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * @param  array<int,mixed>  $raw
     * @param  array<string,int>  $header
     * @return array<string,mixed>
     */
    private function extractRow(array $raw, array $header): array
    {
        $data = [];

        foreach ($header as $key => $index) {
            $value = $raw[$index] ?? null;
            $data[$key] = is_string($value) ? trim($value) : $value;
        }

        return $data;
    }

    private function isBlankRow(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function validateRow(
        array $data,
        int $lineNumber,
        $groups,
        $uoms,
        $taxRates,
        $existing,
        Company $company,
    ): array {
        $validator = Validator::make($data, [
            'codigo' => ['required', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:255'],
            'unidad' => ['required', 'string'],
            'codigo_barras' => ['nullable', 'string', 'max:255'],
            'minimo' => ['nullable', 'numeric', 'min:0'],
            'maximo' => ['nullable', 'numeric', 'min:0'],
            'cabys' => ['nullable', 'string', 'size:13', 'regex:/^\d{13}$/'],
            'unidad_hacienda' => ['nullable', Rule::in(array_keys(FiscalCatalogs::UNITS))],
            'tarifa_iva' => ['nullable', Rule::in(array_keys(FiscalCatalogs::IVA_RATES))],
        ]);

        $errors = [];

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Fila {$lineNumber}: {$message}";
            }
        }

        foreach (array_keys(self::BOOLEAN_COLUMNS) as $column) {
            if (! $this->isValidBooleanLabel($data[$column] ?? null)) {
                $errors[] = "Fila {$lineNumber}: la columna \"{$column}\" debe ser \"Sí\", \"No\" o quedar vacía.";
            }
        }

        // Los tres catálogos que se resuelven por código: decir cuál código
        // no existe ahorra abrir el sistema a buscarlo.
        $unidad = (string) ($data['unidad'] ?? '');
        if ($unidad !== '' && ! isset($uoms[$unidad])) {
            $errors[] = "Fila {$lineNumber}: la unidad de medida \"{$unidad}\" no existe en la compañía. Ver la hoja \"Códigos válidos\".";
        }

        $grupo = (string) ($data['grupo'] ?? '');
        if ($grupo !== '' && ! isset($groups[$grupo])) {
            $errors[] = "Fila {$lineNumber}: el grupo \"{$grupo}\" no existe en la compañía. Ver la hoja \"Códigos válidos\".";
        }

        $impuesto = (string) ($data['impuesto'] ?? '');
        if ($impuesto !== '' && ! isset($taxRates[$impuesto])) {
            $errors[] = "Fila {$lineNumber}: el indicador de impuesto \"{$impuesto}\" no existe. Ver la hoja \"Códigos válidos\".";
        }

        // A partir de acá las reglas de negocio, que son las mismas que
        // aplica la ficha. Solo se evalúan si lo anterior pasó: sin unidad
        // resuelta no hay artículo que validar.
        if ($errors !== []) {
            return $errors;
        }

        $esInventario = $this->normalizeBooleanLabel($data['es_inventario'] ?? null)
            ?? self::BOOLEAN_COLUMNS['es_inventario']['default'];

        if ($impuesto !== '' && ($data['tarifa_iva'] ?? '') !== '') {
            $conflict = ItemFiscalConsistency::error((int) $taxRates[$impuesto], (string) $data['tarifa_iva']);

            if ($conflict !== null) {
                $errors[] = "Fila {$lineNumber}: {$conflict}";
            }
        }

        // Mismo tope que en la ficha y en los niveles por almacén: un máximo
        // por debajo del mínimo deja la sugerencia en cero y el artículo no
        // se repone nunca, en silencio.
        $minimo = filled($data['minimo'] ?? null) ? (string) $data['minimo'] : '0';
        $maximo = $data['maximo'] ?? null;

        if ($esInventario && filled($maximo) && bccomp((string) $maximo, $minimo, 6) < 0) {
            $errors[] = "Fila {$lineNumber}: el máximo ({$maximo}) no puede ser menor que el mínimo ({$minimo}): la sugerencia de compra quedaría en cero.";
        }

        // Un artículo con existencia no se convierte en servicio. La ficha lo
        // impide y el archivo no puede ser la puerta de atrás: dejaría
        // existencia registrada contra un artículo que dice no llevar
        // kardex.
        $code = trim((string) $data['codigo']);
        $current = $existing[$code] ?? null;

        if (! $esInventario && $current !== null && $current->is_inventory_item) {
            $item = Item::find($current->id);

            if ($item !== null && bccomp($item->onHand(), '0.000000', 6) !== 0) {
                $errors[] = "Fila {$lineNumber}: el artículo {$code} todavía tiene existencias; no se puede convertir en servicio hasta dejarlo en cero.";
            }
        }

        return $errors;
    }

    private function isValidBooleanLabel(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return $this->normalizeBooleanLabel($value) !== null;
    }

    private function normalizeBooleanLabel(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtr(strtolower(trim((string) $value)), ['í' => 'i']);

        return match ($normalized) {
            'si', 's', 'true', '1', 'x' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function mapToAttributes(array $data, $groups, $uoms, $taxRates): array
    {
        $flags = [];

        foreach (self::BOOLEAN_COLUMNS as $column => $spec) {
            $flags[$spec['field']] = $this->normalizeBooleanLabel($data[$column] ?? null) ?? $spec['default'];
        }

        $esInventario = $flags['is_inventory_item'];
        $grupo = (string) ($data['grupo'] ?? '');
        $impuesto = (string) ($data['impuesto'] ?? '');

        return [
            'name' => trim((string) $data['nombre']),
            'item_group_id' => $grupo !== '' ? $groups[$grupo] : null,
            'uom_id' => $uoms[(string) $data['unidad']],
            'barcode' => filled($data['codigo_barras'] ?? null) ? trim((string) $data['codigo_barras']) : null,
            'is_inventory_item' => $esInventario,
            'is_sales_item' => $flags['is_sales_item'],
            'is_purchase_item' => $flags['is_purchase_item'],
            // Un servicio no lleva kardex, así que tampoco lotes ni niveles
            // de reposición. Mismo criterio que la ficha.
            'tracks_lots' => $esInventario && $flags['tracks_lots'],
            'minimum_stock' => $esInventario && filled($data['minimo'] ?? null) ? $data['minimo'] : 0,
            'maximum_stock' => $esInventario && filled($data['maximo'] ?? null) ? $data['maximo'] : null,
            'cabys_code' => filled($data['cabys'] ?? null) ? trim((string) $data['cabys']) : null,
            'fiscal_unit_code' => filled($data['unidad_hacienda'] ?? null) ? trim((string) $data['unidad_hacienda']) : null,
            'iva_rate_code' => filled($data['tarifa_iva'] ?? null) ? trim((string) $data['tarifa_iva']) : null,
            'tax_rate_id' => $impuesto !== '' ? $taxRates[$impuesto] : null,
            'status' => $flags['status'] ? 'active' : 'inactive',
        ];
    }
}
