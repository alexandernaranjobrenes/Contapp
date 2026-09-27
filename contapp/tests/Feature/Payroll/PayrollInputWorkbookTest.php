<?php

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PayrollInputWorkbook;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * El XLSX de movimientos: se descarga lleno, se completa y se vuelve a subir.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function workbookFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'costCenter');
}

function workbookEmployee(array $f, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "W{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '8'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Hoja',
        'last_name1' => "Calculo{$n}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '900000.00',
        'weekly_hours' => '48',
        'status' => 'active',
    ], $attributes));
}

function workbookPeriod(array $f, int $number = 6): PayrollPeriod
{
    return PayrollPeriod::create([
        'company_id' => $f['company']->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => $number,
        'name' => "Período {$number} 2026",
        'start_date' => "2026-{$number}-01",
        'end_date' => date('Y-m-t', strtotime("2026-{$number}-01")),
        'payment_date' => date('Y-m-t', strtotime("2026-{$number}-01")),
        'status' => 'open',
    ]);
}

/** Descarga la plantilla a un archivo temporal y devuelve su ruta. */
function downloadTemplate(array $f, PayrollPeriod $period): string
{
    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';

    app(PayrollInputWorkbook::class)->template($f['company'], $period, $path);

    return $path;
}

/**
 * Arma un archivo a mano, para los casos que la plantilla no produce: un
 * período equivocado, un código que no existe, una celda con texto.
 *
 * @param  array<int, array<int, mixed>>  $rows
 */
function buildWorkbook(int $periodId, array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRows([
        Row::fromValues(['PLANILLA', 'Prueba']),
        Row::fromValues(['PERIODO_ID', $periodId]),
        Row::fromValues(['Del', '2026-06-01', 'al', '2026-06-30']),
        Row::fromValues(['No cambie las dos primeras filas.']),
        Row::fromValues(['CODIGO', 'TRABAJADOR', 'SALARIO BASE', ...$headers]),
    ]);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return $path;
}

function importFile(PayrollPeriod $period, string $path)
{
    return test()->post(route('payroll-periods.inputs-import', $period->id), [
        'file' => new UploadedFile($path, 'movimientos.xlsx', null, null, true),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────

it('genera la plantilla con los empleados del período', function () {
    $f = workbookFixture();
    workbookEmployee($f);
    workbookEmployee($f);
    // Este ingresó después del período: no debería aparecer.
    workbookEmployee($f, ['hire_date' => '2026-12-01']);

    $period = workbookPeriod($f);

    $this->get(route('payroll-periods.inputs-template', $period->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Se lee de vuelta lo que se generó: es la única forma de saber que el
    // archivo sirve, y no solo que se produjo.
    $path = downloadTemplate($f, $period);

    $reader = new Reader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();
    @unlink($path);

    expect($rows[1][0])->toBe('PERIODO_ID')
        ->and((string) $rows[1][1])->toBe((string) $period->id)
        ->and($rows[4][0])->toBe('CODIGO')
        // Dos trabajadores, no tres: el que ingresa después no entra.
        ->and(count($rows) - 5)->toBe(2);
});

it('carga los movimientos del archivo y los empareja por código', function () {
    $f = workbookFixture();
    $primero = workbookEmployee($f);
    $segundo = workbookEmployee($f);
    $period = workbookPeriod($f);

    $extra = PayrollConcept::where('code', 'HE-SIMPLE')->firstOrFail();
    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    // Las filas van al revés del orden de la plantilla: se emparejan por
    // código, así que ordenar en Excel no le paga a nadie lo del vecino.
    $path = buildWorkbook($period->id, [
        "{$extra->code} — Horas extra (horas)",
        "{$bono->code} — Bonificación (monto)",
    ], [
        [$segundo->code, $segundo->fullName(), 900000, 4, null],
        [$primero->code, $primero->fullName(), 900000, 10, 50000],
    ]);

    importFile($period, $path)->assertSessionHasNoErrors();
    @unlink($path);

    $inputs = PayrollInput::where('payroll_period_id', $period->id)->get();

    expect($inputs)->toHaveCount(3);

    $delPrimero = $inputs->where('employee_id', $primero->id);

    expect((float) $delPrimero->firstWhere('payroll_concept_id', $extra->id)->quantity)->toBe(10.0)
        ->and($delPrimero->firstWhere('payroll_concept_id', $bono->id)->amount)->toBe('50000.00')
        ->and((float) $inputs->firstWhere('employee_id', $segundo->id)->quantity)->toBe(4.0);
});

it('la carga REEMPLAZA los movimientos del período en vez de sumarse', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $period = workbookPeriod($f);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    $header = ["{$bono->code} — Bonificación (monto)"];

    $primera = buildWorkbook($period->id, $header, [[$employee->code, $employee->fullName(), 900000, 50000]]);
    importFile($period, $primera)->assertSessionHasNoErrors();
    @unlink($primera);

    // Se sube el archivo corregido. Si se sumara, el trabajador cobraría
    // 90.000 en vez de 40.000 — la trampa clásica de toda carga masiva.
    $segunda = buildWorkbook($period->id, $header, [[$employee->code, $employee->fullName(), 900000, 40000]]);
    importFile($period, $segunda)->assertSessionHasNoErrors();
    @unlink($segunda);

    $inputs = PayrollInput::where('payroll_period_id', $period->id)->get();

    expect($inputs)->toHaveCount(1)
        ->and($inputs->first()->amount)->toBe('40000.00');
});

it('rechaza la plantilla de otro período', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $junio = workbookPeriod($f, 6);
    $julio = workbookPeriod($f, 7);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    // Sin esta validación, la plantilla de junio subida a julio pagaría los
    // movimientos de junio otra vez.
    $path = buildWorkbook($junio->id, ["{$bono->code} — Bonificación (monto)"], [
        [$employee->code, $employee->fullName(), 900000, 50000],
    ]);

    importFile($julio, $path)->assertSessionHasErrors('payroll_import');
    @unlink($path);

    expect(PayrollInput::count())->toBe(0);
});

it('no carga nada si una sola celda está mal', function () {
    $f = workbookFixture();
    $bueno = workbookEmployee($f);
    $period = workbookPeriod($f);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    $path = buildWorkbook($period->id, ["{$bono->code} — Bonificación (monto)"], [
        [$bueno->code, $bueno->fullName(), 900000, 50000],
        ['NO-EXISTE', 'Fantasma', 900000, 30000],
    ]);

    importFile($period, $path)->assertSessionHasErrors('payroll_import');
    @unlink($path);

    // Una carga a medias deja sin saber qué quedó adentro, y averiguarlo
    // significa revisar las boletas a mano.
    expect(PayrollInput::count())->toBe(0);
});

it('rechaza una celda que no es un número', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $period = workbookPeriod($f);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    $path = buildWorkbook($period->id, ["{$bono->code} — Bonificación (monto)"], [
        [$employee->code, $employee->fullName(), 900000, 'cincuenta mil'],
    ]);

    importFile($period, $path)->assertSessionHasErrors('payroll_import');
    @unlink($path);

    expect(PayrollInput::count())->toBe(0);
});

it('rechaza valores negativos: el signo lo pone el rubro', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $period = workbookPeriod($f);

    $incapacidad = PayrollConcept::where('code', 'HORAS-INC')->firstOrFail();

    // Las horas de incapacidad se digitan en positivo: el rubro sabe que
    // restan. Aceptar negativos abriría la puerta a que cualquier rubro
    // restara por un error de tecleo.
    $path = buildWorkbook($period->id, ["{$incapacidad->code} — Horas por incapacidad (horas)"], [
        [$employee->code, $employee->fullName(), 900000, -8],
    ]);

    importFile($period, $path)->assertSessionHasErrors('payroll_import');
    @unlink($path);
});

it('no carga sobre un período que ya no admite cambios', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $period = workbookPeriod($f);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();
    $path = buildWorkbook($period->id, ["{$bono->code} — Bonificación (monto)"], [
        [$employee->code, $employee->fullName(), 900000, 50000],
    ]);

    $period->update(['status' => 'approved']);

    importFile($period, $path)->assertSessionHasErrors('payroll_import');
    @unlink($path);

    expect(PayrollInput::count())->toBe(0);
});

it('la plantilla trae lo que ya estaba digitado, para corregirlo', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $period = workbookPeriod($f);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    PayrollInput::create([
        'company_id' => $f['company']->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'payroll_concept_id' => $bono->id,
        'amount' => '77000',
    ]);

    $path = downloadTemplate($f, $period);

    $reader = new Reader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();
    @unlink($path);

    // La columna del bono trae el 77.000: bajar la plantilla vacía obligaría
    // a volver a digitar todo para corregir un dato.
    $headers = $rows[4];
    $column = array_search("{$bono->code} — Bonificación (monto)", $headers, true);

    expect($column)->not->toBeFalse()
        ->and((float) $rows[5][$column])->toBe(77000.0);
});

it('no se cuela entre compañías', function () {
    $f = workbookFixture();
    $employee = workbookEmployee($f);
    $period = workbookPeriod($f);

    $otra = Company::factory()->create();
    $ajeno = Employee::create([
        'company_id' => $otra->id, 'code' => 'AJENO',
        'identification_type' => 'cedula', 'identification_number' => '999888777',
        'first_name' => 'Ajeno', 'last_name1' => 'Ajeno',
        'hire_date' => '2020-01-01', 'salary_type' => 'mensual',
        'base_salary' => '500000.00', 'weekly_hours' => '48', 'status' => 'active',
    ]);

    $bono = PayrollConcept::where('code', 'BONO')->firstOrFail();

    $path = buildWorkbook($period->id, ["{$bono->code} — Bonificación (monto)"], [
        [$employee->code, $employee->fullName(), 900000, 10000],
        [$ajeno->code, $ajeno->fullName(), 500000, 20000],
    ]);

    // El código de otra compañía no existe para esta: se rechaza como
    // cualquier código desconocido.
    importFile($period, $path)->assertSessionHasErrors('payroll_import');
    @unlink($path);

    expect(PayrollInput::count())->toBe(0);
});
