<?php

namespace App\Domains\Accounting\Services;

use App\Domains\Accounting\DataTransferObjects\JournalEntryImportResult;
use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Core\Models\DocumentType;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Carga masiva de saldos iniciales desde el XLSX que genera
 * OpeningBalanceTemplateExporter. A diferencia de la importación de un
 * asiento manual (JournalEntryBulkImporter, que siempre cae en borrador),
 * esto va DIRECTO a PostJournalService::post() — todo o nada, mismo criterio
 * que ChartOfAccountBulkImporter: un trial balance de apertura, por
 * definición, tiene que cuadrar; no tiene sentido dejarlo a medias en un
 * borrador que alguien tendría que acordarse de terminar.
 *
 * El tipo de documento SIEMPRE es el reservado de la compañía (código "APE",
 * creado de oficio la primera vez que se necesita, ver openingDocumentType())
 * — el archivo no lo declara ni se puede elegir otro, así queda
 * "preconcebido solo para este fin" (docs/decisiones.md 2026-08-24).
 */
class OpeningBalanceBulkImporter
{
    private const REQUIRED_HEADERS = ['cuenta', 'socio', 'moneda', 'debito', 'credito'];

    public function __construct(private readonly PostJournalService $postJournalService) {}

    public function import(
        string $filePath,
        Company $company,
        \DateTimeInterface $postingDate,
        ?string $description = null,
        ?int $createdBy = null,
    ): JournalEntryImportResult {
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
            return new JournalEntryImportResult(null, ['El archivo no tiene filas con saldos para cargar.']);
        }

        [$lines, $errors] = $this->resolveLines($dataRows, $company);

        if ($errors !== []) {
            return new JournalEntryImportResult(null, $errors);
        }

        $documentType = $this->openingDocumentType($company);

        try {
            $entry = $this->postJournalService->post(
                $company,
                $documentType,
                $postingDate,
                $postingDate,
                $lines,
                $description ?? 'Carga de saldos iniciales',
                $createdBy,
            );
        } catch (\RuntimeException $e) {
            return new JournalEntryImportResult(null, [$e->getMessage()]);
        }

        return new JournalEntryImportResult($entry->id, []);
    }

    /**
     * El tipo de documento reservado para asientos de apertura: uno por
     * compañía, creado la primera vez que hace falta (no vía seeder/migración
     * — "creado de oficio por el sistema" cuando el usuario lo usa). No
     * genera período de gracia especial: si su período fiscal está cerrado,
     * PostJournalService::post() lo rechaza igual que cualquier otro asiento.
     */
    private function openingDocumentType(Company $company): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'APE'],
            [
                'name' => 'Asiento de apertura (saldos iniciales)',
                'origin_module' => 'contable',
                'generates_journal' => true,
                'currency_mode' => 'libre',
                // Cada línea con socio que arma este importador ya llega con
                // opensItem=true (ver resolveLines()), así que esto nunca
                // bloquea la carga — queda como red de seguridad ante
                // cualquier otro camino futuro que postee contra este tipo.
                'bp_line_requirement' => 'due_date',
                'is_opening_type' => true,
                'status' => 'active',
            ],
        );
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

            $debito = $this->parseAmount($data['debito'] ?? null);
            $credito = $this->parseAmount($data['credito'] ?? null);

            // Fila que la plantilla precargó (cuenta o socio) pero el usuario
            // dejó tal cual, sin saldo — se ignora en vez de rechazarse, para
            // no obligar a borrar a mano cada cuenta/socio sin movimiento
            // inicial. Comparación estricta a propósito: si "debito"/"credito"
            // vino con un valor no numérico, parseAmount() devuelve null (no
            // 0.0), así que esa fila SÍ sigue de largo y se reporta abajo en
            // vez de tratarse como "sin saldo, se ignora".
            if ($debito === 0.0 && $credito === 0.0) {
                continue;
            }

            $rowErrors = [];
            $accountId = null;
            $businessPartnerId = null;
            $opensItem = false;

            if ($debito === null) {
                $rowErrors[] = "Fila {$lineNumber}: \"debito\" debe ser un número mayor o igual a 0.";
            }

            if ($credito === null) {
                $rowErrors[] = "Fila {$lineNumber}: \"credito\" debe ser un número mayor o igual a 0.";
            }

            if ($debito !== null && $credito !== null && $debito > 0 && $credito > 0) {
                $rowErrors[] = "Fila {$lineNumber}: una fila no puede tener débito y crédito a la vez.";
            }

            if ($cuenta === '' && $socio === '') {
                $rowErrors[] = "Fila {$lineNumber}: hay que indicar una cuenta o un socio de negocio.";
            } elseif ($cuenta !== '' && $socio !== '') {
                $rowErrors[] = "Fila {$lineNumber}: no se puede indicar cuenta y socio en la misma fila.";
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
                    // El saldo que traía el socio antes de este sistema queda
                    // como partida pendiente de verdad — si no, nunca
                    // aparecería en el reporte de antigüedad de saldos ni se
                    // le podría aplicar un cobro/pago futuro.
                    $opensItem = true;
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
                opensItem: $opensItem,
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

            break; // solo la primera hoja ("Saldos iniciales"); "Instrucciones" no se procesa
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
