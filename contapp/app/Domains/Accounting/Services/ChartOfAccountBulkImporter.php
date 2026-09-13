<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\ChartOfAccountImportResult;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Carga masiva del catálogo de cuentas desde el XLSX que genera
 * ChartOfAccountTemplateExporter. Todo-o-nada: si una sola fila tiene un
 * error, no se importa ninguna (docs/decisiones.md 2026-08-13), para que
 * nunca quede un catálogo a medio cargar. Las cuentas cuyo código ya
 * existe en la compañía se actualizan; las demás se crean.
 */
class ChartOfAccountBulkImporter
{
    private const REQUIRED_HEADERS = ['codigo', 'nombre', 'tipo', 'moneda', 'iva'];

    /** columna del archivo => [campo del modelo, valor por defecto si viene vacía] */
    private const BOOLEAN_COLUMNS = [
        'cuenta_hoja' => ['field' => 'accepts_posting', 'default' => true],
        'exige_socio' => ['field' => 'requires_business_partner', 'default' => false],
        'cuenta_monetaria' => ['field' => 'is_cash_account', 'default' => false],
        'exige_centro_costo' => ['field' => 'requires_cost_center', 'default' => false],
        'activa' => ['field' => 'is_active', 'default' => true],
    ];

    public function import(string $filePath, Company $company): ChartOfAccountImportResult
    {
        $parsed = $this->readRows($filePath);

        if (isset($parsed['fatal'])) {
            return new ChartOfAccountImportResult(0, [$parsed['fatal']]);
        }

        $header = $parsed['header'];
        $missing = array_diff(self::REQUIRED_HEADERS, array_keys($header));

        if ($missing !== []) {
            return new ChartOfAccountImportResult(0, [
                'Al archivo le faltan estas columnas obligatorias: '.implode(', ', $missing).'.',
            ]);
        }

        $errors = [];
        $seenCodes = [];
        $validRows = [];

        foreach ($parsed['rows'] as $lineNumber => $raw) {
            $data = $this->extractRow($raw, $header);

            if ($this->isBlankRow($data)) {
                continue;
            }

            $rowErrors = $this->validateRow($data, $lineNumber);

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
            $validRows[] = $this->mapToAttributes($data);
        }

        if ($errors !== []) {
            return new ChartOfAccountImportResult(0, $errors);
        }

        if ($validRows === []) {
            return new ChartOfAccountImportResult(0, ['El archivo no tiene filas con datos para importar.']);
        }

        DB::transaction(function () use ($validRows, $company): void {
            foreach ($validRows as $attributes) {
                ChartOfAccount::updateOrCreate(
                    ['company_id' => $company->id, 'code' => $attributes['code']],
                    [...$attributes, 'level' => 1],
                );
            }
        });

        return new ChartOfAccountImportResult(count($validRows), []);
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

            break; // solo la primera hoja ("Catálogo"); "Instrucciones" no se procesa
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
    private function validateRow(array $data, int $lineNumber): array
    {
        $validator = Validator::make($data, [
            'codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:255'],
            'nombre_en' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(array_values(ChartOfAccount::ACCOUNT_TYPES))],
            'moneda' => ['required', Rule::in(array_values(ChartOfAccount::CURRENCY_MODES))],
            'iva' => ['required', Rule::in(array_values(ChartOfAccount::TAX_CLASSIFICATIONS))],
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
    private function mapToAttributes(array $data): array
    {
        $accountType = array_flip(ChartOfAccount::ACCOUNT_TYPES)[$data['tipo']];

        $attributes = [
            'code' => trim((string) $data['codigo']),
            'description_es' => trim((string) $data['nombre']),
            'description_en' => filled($data['nombre_en'] ?? null) ? trim((string) $data['nombre_en']) : null,
            'account_type' => $accountType,
            'normal_balance' => ChartOfAccount::normalBalanceFor($accountType),
            'currency_mode' => array_flip(ChartOfAccount::CURRENCY_MODES)[$data['moneda']],
            'tax_classification' => array_flip(ChartOfAccount::TAX_CLASSIFICATIONS)[$data['iva']],
        ];

        foreach (self::BOOLEAN_COLUMNS as $column => $spec) {
            $attributes[$spec['field']] = $this->normalizeBooleanLabel($data[$column] ?? null) ?? $spec['default'];
        }

        return $attributes;
    }
}
