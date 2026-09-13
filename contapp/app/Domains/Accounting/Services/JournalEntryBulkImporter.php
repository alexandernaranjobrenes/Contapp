<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalEntryImportResult;
use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\Currency;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Importa UN asiento completo (encabezado + líneas) desde el XLSX que genera
 * JournalEntryTemplateExporter. A diferencia de la carga masiva del catálogo
 * de cuentas (todo-o-nada, contabiliza de una vez), acá el destino siempre
 * es un borrador vía PostJournalService::saveDraft(): si el archivo tiene
 * algún código que no existe en la compañía, no se importa nada (esa parte
 * sí es todo-o-nada); pero si los códigos resuelven bien aunque el asiento
 * no cuadre o le falte un dato, igual se guarda como preliminar para
 * revisarlo en pantalla — mismo criterio de tolerancia que "Guardar como
 * preliminar" en el formulario manual.
 */
class JournalEntryBulkImporter
{
    private const REQUIRED_HEADERS = ['tipo_documento', 'fecha', 'cuenta', 'socio', 'moneda', 'debito', 'credito'];

    public function __construct(private readonly PostJournalService $postJournalService)
    {
    }

    public function import(string $filePath, Company $company, ?int $createdBy = null): JournalEntryImportResult
    {
        $parsed = $this->readRows($filePath);

        if (isset($parsed['fatal'])) {
            return new JournalEntryImportResult(null, [$parsed['fatal']]);
        }

        $header = $parsed['header'];
        $missing = array_diff(self::REQUIRED_HEADERS, array_keys($header));

        if ($missing !== []) {
            return new JournalEntryImportResult(null, [
                'Al archivo le faltan estas columnas obligatorias: '.implode(', ', $missing).'.',
            ]);
        }

        $dataRows = [];

        foreach ($parsed['rows'] as $lineNumber => $raw) {
            $data = $this->extractRow($raw, $header);

            if ($this->isBlankRow($data)) {
                continue;
            }

            $dataRows[$lineNumber] = $data;
        }

        if ($dataRows === []) {
            return new JournalEntryImportResult(null, ['El archivo no tiene líneas con datos para importar.']);
        }

        [$documentType, $documentDate, $description, $headerErrors] = $this->resolveHeader($dataRows, $company);
        [$lines, $lineErrors] = $this->resolveLines($dataRows, $company);

        $errors = [...$headerErrors, ...$lineErrors];

        if ($errors !== []) {
            return new JournalEntryImportResult(null, $errors);
        }

        // La plantilla no trae una columna separada para fecha de
        // contabilización (solo "fecha") — se usa la misma fecha para
        // ambas al importar; como todo import cae en borrador, el usuario
        // puede corregir la fecha de contabilización en pantalla antes de
        // contabilizar formalmente si de verdad difieren.
        $entry = $this->postJournalService->saveDraft(
            $company,
            $documentType,
            $documentDate,
            $documentDate,
            $lines,
            $description,
            $createdBy,
        );

        return new JournalEntryImportResult($entry->id, []);
    }

    /**
     * El tipo de documento, la fecha y la descripción del asiento solo se
     * necesitan en la primera fila con datos; en las siguientes se pueden
     * dejar en blanco, pero si vienen llenas deben coincidir — un archivo
     * es UN solo asiento, no varios encimados por error de copiar/pegar.
     *
     * @param  array<int,array<string,mixed>>  $dataRows
     * @return array{0: ?DocumentType, 1: ?\DateTimeImmutable, 2: ?string, 3: string[]}
     */
    private function resolveHeader(array $dataRows, Company $company): array
    {
        $errors = [];
        $firstLineNumber = array_key_first($dataRows);
        $first = $dataRows[$firstLineNumber];

        $documentTypeCode = trim((string) ($first['tipo_documento'] ?? ''));
        $fechaRaw = trim((string) ($first['fecha'] ?? ''));
        $description = filled($first['descripcion_asiento'] ?? null) ? trim((string) $first['descripcion_asiento']) : null;

        $documentType = null;

        if ($documentTypeCode === '') {
            $errors[] = "Fila {$firstLineNumber}: falta el tipo de documento (se necesita en la primera fila con datos).";
        } else {
            $documentType = DocumentType::where('company_id', $company->id)
                ->where('code', $documentTypeCode)
                ->where('status', 'active')
                ->where('generates_journal', true)
                ->first();

            if (! $documentType) {
                $errors[] = "Fila {$firstLineNumber}: el tipo de documento \"{$documentTypeCode}\" no existe, no está activo, o no genera asientos.";
            }
        }

        $documentDate = null;

        if ($fechaRaw === '') {
            $errors[] = "Fila {$firstLineNumber}: falta la fecha (se necesita en la primera fila con datos).";
        } else {
            $documentDate = $this->parseDate($fechaRaw);

            if ($documentDate === null) {
                $errors[] = "Fila {$firstLineNumber}: la fecha \"{$fechaRaw}\" no es válida (formato esperado AAAA-MM-DD).";
            }
        }

        foreach ($dataRows as $lineNumber => $data) {
            if ($lineNumber === $firstLineNumber) {
                continue;
            }

            foreach (['tipo_documento' => $documentTypeCode, 'fecha' => $fechaRaw, 'descripcion_asiento' => $first['descripcion_asiento'] ?? null] as $column => $expected) {
                $value = $data[$column] ?? null;
                $value = is_string($value) ? trim($value) : $value;

                if ($value !== null && $value !== '' && (string) $value !== (string) $expected) {
                    $errors[] = "Fila {$lineNumber}: \"{$column}\" no coincide con la primera fila; un archivo representa un solo asiento.";
                }
            }
        }

        return [$documentType, $documentDate, $description, $errors];
    }

    /**
     * @param  array<int,array<string,mixed>>  $dataRows
     * @return array{0: JournalLineInput[], 1: string[]}
     */
    private function resolveLines(array $dataRows, Company $company): array
    {
        $accountCache = [];
        $partnerCache = [];
        $currencyCache = [];
        $ruleCache = [];
        $lines = [];
        $errors = [];

        foreach ($dataRows as $lineNumber => $data) {
            $cuenta = trim((string) ($data['cuenta'] ?? ''));
            $socio = trim((string) ($data['socio'] ?? ''));
            $moneda = trim((string) ($data['moneda'] ?? ''));
            $normaReparto = trim((string) ($data['norma_reparto'] ?? ''));
            $descripcionLinea = filled($data['descripcion_linea'] ?? null) ? trim((string) $data['descripcion_linea']) : null;
            $documentoReferencia = filled($data['documento_referencia'] ?? null) ? trim((string) $data['documento_referencia']) : null;
            $fechaDocumentoReferenciaRaw = trim((string) ($data['fecha_documento_referencia'] ?? ''));

            $rowErrors = [];
            $accountId = null;
            $businessPartnerId = null;

            if ($cuenta === '' && $socio === '') {
                $rowErrors[] = "Fila {$lineNumber}: hay que indicar una cuenta o un socio de negocio.";
            } elseif ($cuenta !== '' && $socio !== '') {
                $rowErrors[] = "Fila {$lineNumber}: no se puede indicar cuenta y socio en la misma línea.";
            } elseif ($cuenta !== '') {
                $accountCache[$cuenta] ??= ChartOfAccount::where('company_id', $company->id)->where('code', $cuenta)->first();

                if (! $accountCache[$cuenta]) {
                    $rowErrors[] = "Fila {$lineNumber}: la cuenta \"{$cuenta}\" no existe en la compañía.";
                } else {
                    $accountId = $accountCache[$cuenta]->id;
                }
            } else {
                $partnerCache[$socio] ??= BusinessPartner::where('company_id', $company->id)->where('code', $socio)->first();
                $partner = $partnerCache[$socio];

                if (! $partner) {
                    $rowErrors[] = "Fila {$lineNumber}: el socio \"{$socio}\" no existe en la compañía.";
                } elseif (! $partner->gl_account_id) {
                    $rowErrors[] = "Fila {$lineNumber}: el socio \"{$socio}\" no tiene cuenta contable de control asociada.";
                } else {
                    $accountId = $partner->gl_account_id;
                    $businessPartnerId = $partner->id;
                }
            }

            $currencyId = null;

            if ($moneda === '') {
                $rowErrors[] = "Fila {$lineNumber}: falta la moneda.";
            } else {
                $currencyCache[$moneda] ??= Currency::where('code', $moneda)->first();

                if (! $currencyCache[$moneda]) {
                    $rowErrors[] = "Fila {$lineNumber}: la moneda \"{$moneda}\" no existe.";
                } else {
                    $currencyId = $currencyCache[$moneda]->id;
                }
            }

            $costAllocationRuleId = null;

            if ($normaReparto !== '') {
                $ruleCache[$normaReparto] ??= CostAllocationRule::where('company_id', $company->id)->where('code', $normaReparto)->first();

                if (! $ruleCache[$normaReparto]) {
                    $rowErrors[] = "Fila {$lineNumber}: la norma de reparto \"{$normaReparto}\" no existe en la compañía.";
                } else {
                    $costAllocationRuleId = $ruleCache[$normaReparto]->id;
                }
            }

            $debito = $this->parseAmount($data['debito'] ?? null);
            $credito = $this->parseAmount($data['credito'] ?? null);

            if ($debito === null) {
                $rowErrors[] = "Fila {$lineNumber}: \"debito\" debe ser un número mayor o igual a 0.";
            }

            if ($credito === null) {
                $rowErrors[] = "Fila {$lineNumber}: \"credito\" debe ser un número mayor o igual a 0.";
            }

            if ($debito !== null && $credito !== null && $debito > 0 && $credito > 0) {
                $rowErrors[] = "Fila {$lineNumber}: una línea no puede tener débito y crédito a la vez.";
            }

            $referenceDocumentDate = null;

            if ($fechaDocumentoReferenciaRaw !== '') {
                $referenceDocumentDate = $this->parseDate($fechaDocumentoReferenciaRaw)?->format('Y-m-d');

                if ($referenceDocumentDate === null) {
                    $rowErrors[] = "Fila {$lineNumber}: la fecha de documento de referencia \"{$fechaDocumentoReferenciaRaw}\" no es válida (formato esperado AAAA-MM-DD).";
                }
            }

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $lines[] = new JournalLineInput(
                accountId: $accountId,
                currencyId: $currencyId,
                debit: $debito,
                credit: $credito,
                description: $descripcionLinea,
                businessPartnerId: $businessPartnerId,
                costAllocationRuleId: $costAllocationRuleId,
                allowZeroAmount: true,
                referenceDocument: $documentoReferencia,
                referenceDocumentDate: $referenceDocumentDate,
            );
        }

        return [$lines, $errors];
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

            break; // solo la primera hoja ("Asiento"); "Instrucciones" no se procesa
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

            // Si la celda tiene formato de fecha en Excel (no texto plano),
            // OpenSpout la entrega como DateTimeImmutable en vez de string —
            // sin esto, cualquier (string) $value más adelante (resolveHeader)
            // revienta porque DateTimeImmutable no implementa __toString().
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }

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

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number < 0 ? null : $number;
    }
}
