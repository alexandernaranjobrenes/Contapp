<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\DataTransferObjects\PayrollInputImportResult;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * El XLSX de movimientos del período: se descarga lleno, se completa y se
 * vuelve a subir.
 *
 * ── Por qué una rejilla y no una fila por movimiento ─────────────────────
 *
 * La ficha de un empleado se lee mejor en vertical —rubro por rubro, con sus
 * totales— y así es como se revisa uno por uno en pantalla. Pero para cargar
 * cincuenta personas en un archivo, lo que se llena rápido es una rejilla:
 * un trabajador por fila, un rubro por columna. Es la forma en que ya viene
 * la información de los jefes de área.
 *
 * ── Los empleados se emparejan por CÓDIGO, no por posición ───────────────
 *
 * Si alguien inserta una fila, ordena por nombre o borra un renglón, una
 * carga por posición le pagaría a cada persona lo del vecino. El código es
 * el único dato que sobrevive a que alguien trabaje el archivo en Excel.
 *
 * ── La carga REEMPLAZA los movimientos del período ───────────────────────
 *
 * Y es a propósito. El archivo es «los movimientos de esta quincena»: si al
 * subir un archivo corregido se sumara a lo anterior, el segundo intento
 * pagaría el doble — y esa es la trampa clásica de toda carga masiva.
 * Reemplazar hace que subir el archivo corregido simplemente funcione.
 *
 * Por eso el resultado dice cuántos movimientos se llevó por delante, no
 * solo cuántos entraron.
 *
 * ── Todo o nada ──────────────────────────────────────────────────────────
 *
 * Si una sola celda está mal, no entra ninguna. Una carga a medias deja al
 * usuario sin saber qué quedó adentro, y en una planilla eso significa
 * revisar cincuenta boletas a mano para averiguarlo.
 */
class PayrollInputWorkbook
{
    /** La celda que ata el archivo a su período. Ver validatePeriod(). */
    private const PERIOD_MARKER = 'PERIODO_ID';

    private const HEADER_ROW_MARKER = 'CODIGO';

    /**
     * Genera la plantilla del período, con los empleados que entran y los
     * movimientos que ya estén digitados.
     */
    public function template(Company $company, PayrollPeriod $period, string $outputPath): void
    {
        $employees = $this->includedEmployees($company, $period);
        $concepts = $this->digitableConcepts($company);

        $existing = PayrollInput::where('payroll_period_id', $period->id)
            ->get()
            ->groupBy('employee_id');

        $writer = new Writer;
        $writer->openToFile($outputPath);
        $writer->getCurrentSheet()->setName('Movimientos');

        $bold = (new Style)->setFontBold();
        $header = (new Style)->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');

        $writer->addRows([
            Row::fromValues(['PLANILLA', $period->name], $bold),
            // Esta fila ata el archivo a SU período. Sin ella, la plantilla de
            // marzo se podría subir a la planilla de abril sin que nada lo
            // note, y pagaría las horas extra de marzo otra vez.
            Row::fromValues([self::PERIOD_MARKER, $period->id]),
            Row::fromValues([
                'Del', $period->start_date->format('Y-m-d'),
                'al', $period->end_date->format('Y-m-d'),
            ]),
            Row::fromValues(['No cambie las dos primeras filas ni los encabezados de la fila 5.']),
            Row::fromValues([
                self::HEADER_ROW_MARKER, 'TRABAJADOR', 'SALARIO BASE',
                ...$concepts->map(fn (PayrollConcept $c) => $this->columnHeader($c))->all(),
            ], $header),
        ]);

        foreach ($employees as $employee) {
            $values = [$employee->code, $employee->fullName(), (float) $employee->base_salary];

            $byConcept = ($existing[$employee->id] ?? collect())->keyBy('payroll_concept_id');

            foreach ($concepts as $concept) {
                $input = $byConcept->get($concept->id);

                if ($input === null) {
                    $values[] = null;

                    continue;
                }

                $values[] = $concept->calculation === 'hours'
                    ? (float) $input->quantity
                    : (float) $input->amount;
            }

            $writer->addRow(Row::fromValues($values));
        }

        $this->conceptSheet($writer, $concepts, $bold, $header);
        $this->instructionsSheet($writer, $period, $bold);

        $writer->close();
    }

    /**
     * Lee el archivo y reemplaza los movimientos digitados del período.
     */
    public function import(Company $company, PayrollPeriod $period, string $filePath): PayrollInputImportResult
    {
        if (! $period->isRecalculable()) {
            return new PayrollInputImportResult(fatal: "El período «{$period->name}» ya está ".
                mb_strtolower(PayrollPeriod::STATUSES[$period->status] ?? $period->status).
                ': no admite movimientos nuevos.');
        }

        $read = $this->readSheet($filePath);

        if (isset($read['fatal'])) {
            return new PayrollInputImportResult(fatal: $read['fatal']);
        }

        if ((string) $read['periodId'] !== (string) $period->id) {
            return new PayrollInputImportResult(fatal: 'Este archivo es la plantilla de otro período. Descargá la plantilla de '.
                "«{$period->name}» y volvé a llenarla: subir la de otro período pagaría sus movimientos otra vez.");
        }

        $concepts = $this->digitableConcepts($company)->keyBy('code');
        $employees = Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('code');

        $columns = [];
        $errors = [];

        // Las columnas se resuelven por el CÓDIGO del rubro que lleva el
        // encabezado, no por su posición: así alguien puede reordenar o
        // borrar columnas que no usa sin romper la carga.
        foreach ($read['header'] as $index => $label) {
            if ($index < 3) {
                continue;
            }

            $code = trim(explode('—', (string) $label)[0]);

            if ($code === '') {
                continue;
            }

            if (! $concepts->has($code)) {
                $errors[] = "El encabezado «{$label}» no corresponde a ningún rubro activo del catálogo.";

                continue;
            }

            $columns[$index] = $concepts->get($code);
        }

        $pending = [];
        $seen = [];

        foreach ($read['rows'] as $lineNumber => $values) {
            $code = trim((string) ($values[0] ?? ''));

            if ($code === '') {
                continue;
            }

            if (! $employees->has($code)) {
                $errors[] = "Fila {$lineNumber}: no existe ningún trabajador con código «{$code}».";

                continue;
            }

            $employee = $employees->get($code);
            $seen[$employee->id] = true;

            foreach ($columns as $index => $concept) {
                $raw = $values[$index] ?? null;

                if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
                    continue;
                }

                if (! is_numeric($raw)) {
                    $errors[] = "Fila {$lineNumber}, rubro {$concept->code}: «{$raw}» no es un número.";

                    continue;
                }

                if ((float) $raw < 0) {
                    $errors[] = "Fila {$lineNumber}, rubro {$concept->code}: no se admiten valores negativos.";

                    continue;
                }

                $pending[] = [
                    'company_id' => $company->id,
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'payroll_concept_id' => $concept->id,
                    'amount' => $concept->calculation === 'hours' ? null : $raw,
                    'quantity' => $concept->calculation === 'hours' ? $raw : null,
                    'notes' => 'Carga masiva',
                ];
            }
        }

        // Todo o nada: una carga a medias deja al usuario sin saber qué quedó
        // adentro, y averiguarlo significa revisar las boletas a mano.
        if ($errors !== []) {
            return new PayrollInputImportResult(errors: array_slice($errors, 0, 25));
        }

        if ($pending === []) {
            return new PayrollInputImportResult(fatal: 'El archivo no trae ningún movimiento con valor. Si era eso lo que querías, '.
                'borrá los movimientos desde la pantalla del período.');
        }

        return DB::transaction(function () use ($period, $pending, $seen) {
            $removed = PayrollInput::where('payroll_period_id', $period->id)->count();

            PayrollInput::where('payroll_period_id', $period->id)->delete();

            foreach ($pending as $row) {
                PayrollInput::create($row);
            }

            return new PayrollInputImportResult(
                created: count($pending),
                removed: $removed,
                employees: count($seen),
            );
        });
    }

    /**
     * Los rubros que se pueden digitar. Las cargas sociales y el impuesto NO
     * están acá: los calcula el motor, y poder digitarlos permitiría cuadrar
     * una planilla a mano y romper la conciliación con la Caja.
     *
     * @return Collection<int, PayrollConcept>
     */
    private function digitableConcepts(Company $company)
    {
        return PayrollConcept::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderByRaw("CASE WHEN type = 'earning' THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function includedEmployees(Company $company, PayrollPeriod $period)
    {
        return Employee::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->whereIn('status', ['active', 'suspended'])
            ->orderBy('code')
            ->get()
            ->filter(fn (Employee $e) => $e->wasEmployedDuring(
                $period->start_date->format('Y-m-d'),
                $period->end_date->format('Y-m-d')
            ))
            ->values();
    }

    /** El encabezado lleva el código adelante: es lo que el lector busca. */
    private function columnHeader(PayrollConcept $concept): string
    {
        $unit = $concept->calculation === 'hours' ? 'horas' : 'monto';

        return "{$concept->code} — {$concept->name} ({$unit})";
    }

    /**
     * @return array{fatal?: string, periodId?: mixed, header?: array<int, mixed>, rows?: array<int, array<int, mixed>>}
     */
    private function readSheet(string $filePath): array
    {
        $reader = new Reader;

        try {
            $reader->open($filePath);
        } catch (\Throwable) {
            return ['fatal' => 'No se pudo leer el archivo. Verificá que sea el .xlsx de la plantilla.'];
        }

        $periodId = null;
        $header = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $lineNumber = 0;
            $headerFound = false;

            foreach ($sheet->getRowIterator() as $row) {
                $lineNumber++;
                $values = $row->toArray();
                $first = trim((string) ($values[0] ?? ''));

                if ($first === self::PERIOD_MARKER) {
                    $periodId = $values[1] ?? null;

                    continue;
                }

                if (! $headerFound) {
                    if ($first === self::HEADER_ROW_MARKER) {
                        $header = $values;
                        $headerFound = true;
                    }

                    continue;
                }

                $rows[$lineNumber] = $values;
            }

            break; // Solo la primera hoja: «Rubros» e «Instrucciones» no se leen.
        }

        $reader->close();

        if ($periodId === null) {
            return ['fatal' => 'El archivo no trae la fila PERIODO_ID. Usá la plantilla que descarga el sistema.'];
        }

        if ($header === []) {
            return ['fatal' => 'El archivo no trae la fila de encabezados. Usá la plantilla que descarga el sistema.'];
        }

        return ['periodId' => $periodId, 'header' => $header, 'rows' => $rows];
    }

    /** @param  Collection<int, PayrollConcept>  $concepts */
    private function conceptSheet(Writer $writer, $concepts, Style $bold, Style $header): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Rubros');

        $writer->addRows([
            Row::fromValues(['Catálogo de rubros digitables'], $bold),
            Row::fromValues(['Las cargas sociales y el impuesto no están acá: los calcula el sistema.']),
            Row::fromValues([]),
            Row::fromValues(['Código', 'Nombre', 'Tipo', 'Se digita', 'Factor', 'Salarial', 'Gravable', 'Provisiona'], $header),
        ]);

        $units = ['amount' => 'Monto', 'hours' => 'Horas', 'percentage' => 'Porcentaje'];

        foreach ($concepts as $concept) {
            $writer->addRow(Row::fromValues([
                $concept->code,
                $concept->name,
                $concept->type === 'earning' ? 'Ingreso' : 'Deducción',
                $units[$concept->calculation] ?? $concept->calculation,
                $concept->factor === null ? null : (float) $concept->factor,
                $concept->affects_ccss ? 'Sí' : 'No',
                $concept->affects_income_tax ? 'Sí' : 'No',
                $concept->affects_provisions ? 'Sí' : 'No',
            ]));
        }
    }

    private function instructionsSheet(Writer $writer, PayrollPeriod $period, Style $bold): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');

        foreach ([
            ["Movimientos de la planilla {$period->name}", true],
            ['', false],
            ['1. Llená un valor por trabajador y rubro en la hoja «Movimientos».', false],
            ['2. Las columnas que dicen (horas) llevan cantidad de horas; las que dicen (monto), el importe.', false],
            ['3. Dejá en blanco lo que no aplique. Un cero se ignora igual que un blanco.', false],
            ['4. Subí el archivo en la pantalla del período.', false],
            ['', false],
            ['Lo que sí podés hacer en Excel:', true],
            ['· Ordenar las filas, insertar o borrar renglones. Los trabajadores se emparejan por su CÓDIGO.', false],
            ['· Borrar columnas de rubros que no vas a usar.', false],
            ['', false],
            ['Lo que NO:', true],
            ['· Cambiar la fila PERIODO_ID: es lo que impide subir esta plantilla a otro período.', false],
            ['· Cambiar el código que aparece al inicio de cada encabezado de rubro.', false],
            ['', false],
            ['La carga REEMPLAZA los movimientos digitados del período.', true],
            ['Así, si subís un archivo corregido, no se suma a lo anterior: lo sustituye.', false],
            ['Si una sola celda está mal, no entra ninguna y el sistema dice cuáles revisar.', false],
        ] as [$text, $isBold]) {
            $writer->addRow($isBold ? Row::fromValues([$text], $bold) : Row::fromValues([$text]));
        }
    }
}
