<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Reports\PayrollReportRegistry;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Reporting\Reports\HasSelectableColumns;
use App\Domains\Reporting\Reports\ReportColumn;
use App\Domains\Reporting\Reports\ReportResult;
use App\Models\User;

/**
 * Los reportes de planilla.
 *
 * Fixture propio del archivo: los helpers de Pest comparten un espacio de
 * nombres global. Se calcula UNA planilla de verdad —no se insertan boletas a
 * mano— porque la mitad del valor de estos reportes está en leer bien lo que el
 * motor produjo, y una boleta inventada a mano no prueba eso.
 */
function payrollReportsFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2020-01-01');

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'expense', 'accepts_posting' => true,
    ]);

    PayrollSetting::create([
        'company_id' => $company->id,
        'salary_expense_account_id' => $expense->id,
        'vacation_days_per_month' => '1',
        'max_deduction_percentage' => '0',
    ]);

    $employees = [];

    foreach ([['R1', '800000.00', false], ['R2', '450000.00', true]] as $i => [$code, $salary, $pensioner]) {
        $employees[$code] = Employee::create([
            'company_id' => $company->id,
            'code' => $code,
            'identification_type' => 'cedula',
            'identification_number' => '90000000'.$i,
            'ccss_number' => "SEG-{$code}",
            'first_name' => 'Reporte',
            'last_name1' => "Uno{$i}",
            'hire_date' => '2019-03-01',
            'cost_center_id' => $costCenter->id,
            'journey_type' => 'diurna',
            'weekly_hours' => '48',
            'salary_type' => 'mensual',
            'base_salary' => $salary,
            'payment_method' => 'transferencia',
            'bank_account' => 'CR0100000000000000'.$i,
            'is_pensioner' => $pensioner,
            'status' => 'active',
        ]);
    }

    // Una obligación viva, para el reporte de préstamos y para que la boleta
    // traiga una línea de deducción.
    EmployeeDeduction::create([
        'company_id' => $company->id,
        'employee_id' => $employees['R1']->id,
        'type' => 'loan',
        // Ligada al concepto del catálogo, que es como se usa de verdad: sin
        // concepto, la línea de la boleta sale con el tipo en mayúsculas.
        'payroll_concept_id' => PayrollConcept::where('code', 'PRESTAMO')->value('id'),
        'reference' => 'PR-1',
        'description' => 'Préstamo personal',
        'start_date' => '2026-01-01',
        'original_amount' => '600000.00',
        'balance' => '600000.00',
        'calculation' => 'fixed',
        'installment_amount' => '50000.00',
        'priority' => 1,
        'status' => 'active',
    ]);

    VacationMovement::create([
        'company_id' => $company->id,
        'employee_id' => $employees['R2']->id,
        'type' => 'accrual',
        'movement_date' => '2026-01-31',
        'days' => '14.0000',
    ]);

    PersonnelAction::create([
        'company_id' => $company->id,
        'employee_id' => $employees['R1']->id,
        'action_type' => 'salary_change',
        'effective_date' => '2026-02-01',
        'field' => 'base_salary',
        'previous_value' => '700000.00',
        'new_value' => '800000.00',
        'reason' => 'Ajuste anual',
        'status' => 'applied',
    ]);

    $period = PayrollPeriod::create([
        'company_id' => $company->id,
        'year' => 2026,
        'frequency' => 'mensual',
        'number' => 3,
        'name' => 'Marzo 2026',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'payment_date' => '2026-03-31',
        'status' => 'open',
    ]);

    app(CalculatePayrollService::class)->calculate($company, $period);

    return compact('company', 'costCenter', 'employees', 'period');
}

function runPayrollReport(string $code, array $f, array $filters = []): ReportResult
{
    $report = app(PayrollReportRegistry::class)->find($code);

    $resolved = [];

    foreach ($report->filters() as $filter) {
        $resolved[$filter->key] = $filters[$filter->key] ?? match ($filter->default) {
            'today' => now()->format('Y-m-d'),
            'first_day_of_month' => now()->startOfMonth()->format('Y-m-d'),
            'first_day_of_year' => now()->startOfYear()->format('Y-m-d'),
            'last_calculated_period' => null,
            default => $filter->default,
        };
    }

    return $report->build($f['company'], $resolved);
}

// ── El contrato, probado sobre TODOS de una vez ──────────────────────────

it('EL CONTRATO: todos los reportes del registro corren y devuelven una estructura válida', function () {
    $f = payrollReportsFixture();

    // Esta prueba es la que hace que el reporte número catorce herede la
    // cobertura sin escribir nada: si el nuevo revienta, se cae acá.
    foreach (app(PayrollReportRegistry::class)->all() as $report) {
        $result = runPayrollReport($report->code(), $f);

        expect($report->code())->not->toBeEmpty()
            ->and($report->label())->not->toBeEmpty()
            // La decisión que ayuda a tomar es obligatoria: es lo que
            // distingue un reporte de otro en un índice de trece.
            ->and($report->decision())->not->toBeEmpty()
            ->and($report->group())->toBeIn(PayrollReportRegistry::GROUP_ORDER)
            ->and($result->columns)->not->toBeEmpty();

        foreach ($result->columns as $column) {
            expect($column)->toBeInstanceOf(ReportColumn::class)
                ->and($column->label)->not->toBeEmpty();
        }

        // Cada columna declarada aparece en al menos UNA fila.
        //
        // No se exige que esté en todas, y eso es deliberado: la planilla de la
        // Caja deja vacía la celda de IVM de un pensionado a propósito, porque
        // «no le aplica» es distinto de «cotizó cero». Lo que esta prueba
        // atrapa es una columna declarada cuya clave nadie llena nunca — una
        // errata en el nombre de la clave— que sale como una columna de guiones
        // y que nadie nota hasta que alguien pregunta por qué está vacía.
        if ($result->rows !== []) {
            foreach ($result->columns as $column) {
                $present = array_filter(
                    $result->rows,
                    fn (array $row) => array_key_exists($column->key, $row)
                );

                expect($present)->not->toBeEmpty(
                    "{$report->code()}: ninguna fila trae la clave «{$column->key}» de la columna ".
                    "«{$column->label}»"
                );
            }
        }
    }
});

it('todos los reportes se consultan por HTTP', function () {
    payrollReportsFixture();

    foreach (app(PayrollReportRegistry::class)->all() as $report) {
        $this->get(route('payroll-reports.show', $report->code()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Reports/Show')
                ->where('report.code', $report->code())
                ->has('columns')
            );
    }
});

it('todos los reportes salen a XLSX, a PDF y a impresión', function () {
    payrollReportsFixture();

    foreach (app(PayrollReportRegistry::class)->all() as $report) {
        $xlsx = $this->get(route('payroll-reports.export', $report->code()));
        $xlsx->assertOk();
        expect($xlsx->headers->get('Content-Disposition'))->toContain($report->code().'.xlsx');

        $pdf = $this->get(route('payroll-reports.export-pdf', $report->code()));
        $pdf->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');

        // Imprimir es el MISMO PDF, pero en línea: se abre en el navegador en
        // vez de descargarse.
        $print = $this->get(route('payroll-reports.print', $report->code()));
        $print->assertOk();
        expect($print->headers->get('Content-Disposition'))->toContain('inline');
    }
});

it('un reporte que no existe da 404 en vez de reventar', function () {
    payrollReportsFixture();

    $this->get(route('payroll-reports.show', 'no-existe'))->assertNotFound();
});

it('el índice agrupa los reportes', function () {
    payrollReportsFixture();

    $this->get(route('payroll-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Payroll/Reports/Index')
            ->has('groups')
            ->has('related')
        );
});

it('exige permiso de lectura sobre planillas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $this->actingAs($user)->get(route('payroll-reports.index'))->assertForbidden();
});

// ── Columnas escogibles ─────────────────────────────────────────────────

it('el reporte de empleados arranca con sus columnas por defecto y no con todas', function () {
    payrollReportsFixture();

    $report = app(PayrollReportRegistry::class)->find('employees');

    expect($report)->toBeInstanceOf(HasSelectableColumns::class);

    // Un reporte que arranca con cuarenta columnas se abre una vez y no se
    // vuelve a abrir.
    expect(count($report->defaultColumns()))->toBeLessThan(count($report->availableColumns()));

    $this->get(route('payroll-reports.show', 'employees'))
        ->assertInertia(fn ($page) => $page
            ->count('columns', count($report->defaultColumns()))
            ->has('availableColumns')
        );
});

it('las columnas escogidas en la URL son las que salen, en el orden del reporte', function () {
    payrollReportsFixture();

    // A propósito en orden invertido respecto de la declaración.
    $this->get(route('payroll-reports.show', ['report' => 'employees', 'columns' => 'base_salary,code']))
        ->assertInertia(fn ($page) => $page
            ->count('columns', 2)
            // Sale en el orden del reporte, no en el que se pidieron: dos
            // exportaciones del mismo reporte tienen que poder compararse
            // columna a columna.
            ->where('columns.0.key', 'code')
            ->where('columns.1.key', 'base_salary')
        );
});

it('una columna inventada en la URL se ignora en vez de romper el reporte', function () {
    payrollReportsFixture();

    $this->get(route('payroll-reports.show', ['report' => 'employees', 'columns' => 'code,no_existe']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('columns', 1));
});

it('una selección vacía cae en las columnas por defecto, no en ninguna', function () {
    payrollReportsFixture();

    $report = app(PayrollReportRegistry::class)->find('employees');

    // Una pantalla sin columnas parece un error del sistema.
    $this->get(route('payroll-reports.show', ['report' => 'employees', 'columns' => 'nada,tampoco']))
        ->assertInertia(fn ($page) => $page->count('columns', count($report->defaultColumns())));
});

it('un reporte sin columnas escogibles no ofrece el control', function () {
    payrollReportsFixture();

    $this->get(route('payroll-reports.show', 'payroll-register'))
        ->assertInertia(fn ($page) => $page
            ->where('availableColumns', null)
            ->where('selectedColumns', null)
        );
});

// ── Cada reporte: lo que lo hace distinto ───────────────────────────────

it('la lista de empleados calcula el valor del día con las reglas del módulo', function () {
    $f = payrollReportsFixture();

    $rows = collect(runPayrollReport('employees', $f)->rows)->keyBy('code');

    // ₡800.000 ÷ 30 = ₡26.666,67. Que salga del mismo método que usa el motor
    // es lo que evita que circulen dos valores del día para la misma persona.
    expect($rows['R1']['daily_rate'])->toBe(26666.66)
        ->and($rows['R1']['monthly_salary'])->toBe(800000.0);
});

it('la lista de empleados encuentra las fichas incompletas', function () {
    $f = payrollReportsFixture();

    Employee::create([
        'company_id' => $f['company']->id,
        'code' => 'R9',
        'identification_type' => 'cedula',
        'identification_number' => '900000099',
        'first_name' => 'Sin',
        'last_name1' => 'Datos',
        'hire_date' => '2026-01-01',
        'journey_type' => 'diurna',
        'weekly_hours' => '48',
        'salary_type' => 'mensual',
        'base_salary' => '0.00',
        'payment_method' => 'transferencia',
        'status' => 'active',
    ]);

    $rows = runPayrollReport('employees', $f, ['incomplete' => true])->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['code'])->toBe('R9')
        // Salario, número de asegurado, centro de costo y cuenta bancaria.
        ->and($rows[0]['missing'])->toContain('salario')
        ->and($rows[0]['missing'])->toContain('cuenta bancaria');
});

it('LA PRUEBA DEL COSTO: la planilla íntegra suma el costo patronal, no solo el bruto', function () {
    $f = payrollReportsFixture();

    $rows = collect(runPayrollReport('payroll-register', $f)->rows)->keyBy('code');
    $row = $rows['R1'];

    // El costo total es bruto + cargas patronales + provisiones, y tiene que
    // ser sensiblemente mayor que el bruto: quien cotiza con el bruto se queda
    // corto en más de un 40%.
    expect($row['employer_cost'])->toBeGreaterThan($row['gross'])
        ->and(round($row['employer_cost'], 2))
        ->toBe(round($row['gross'] + $row['employer_contributions'] + $row['provisions'], 2));
});

it('LA PRUEBA DEL PENSIONADO: la planilla de la Caja deja su celda de IVM vacía, no en cero', function () {
    $f = payrollReportsFixture();

    $result = runPayrollReport('ccss-payroll', $f);
    $rows = collect($result->rows)->keyBy('ccss_number');

    // do002 es IVM obrero en la plantilla costarricense.
    $ivm = 'c_do002';

    expect($rows['SEG-R1'])->toHaveKey($ivm)
        ->and($rows['SEG-R1'][$ivm])->toBeGreaterThan(0);

    // El pensionado no cotiza IVM: vacío dice «no le aplica», y un cero diría
    // «cotizó nada», que es distinto.
    expect($rows['SEG-R2'][$ivm] ?? null)->toBeNull()
        // Pero sí cotiza lo demás, así que su total obrero no es cero.
        ->and($rows['SEG-R2']['total_employee'])->toBeGreaterThan(0);
});

it('la planilla de la Caja no lleva netos ni deducciones', function () {
    $f = payrollReportsFixture();

    $keys = array_map(fn (ReportColumn $c) => $c->key, runPayrollReport('ccss-payroll', $f)->columns);

    // Lo único que le corresponde a la Caja son bases y cuotas.
    expect($keys)->not->toContain('net_pay')
        ->and($keys)->not->toContain('total_other_deductions');
});

it('el impuesto se reporta por mes y no por período', function () {
    $f = payrollReportsFixture();

    $result = runPayrollReport('income-tax', $f, ['month' => '2026-03-15']);

    expect($result->notes[0])->toContain('período(s)')
        ->and(collect($result->rows)->pluck('periods')->unique()->all())->toBe([1]);
});

it('las deducciones se agrupan por rubro y cuentan personas, no rebajos', function () {
    $f = payrollReportsFixture();

    $rows = collect(runPayrollReport('deductions-by-concept', $f, [
        'from' => '2026-03-01', 'to' => '2026-03-31', 'view' => 'summary',
    ])->rows)->keyBy('code');

    expect($rows)->toHaveKey('PRESTAMO')
        ->and($rows['PRESTAMO']['employees'])->toBe(1)
        ->and($rows['PRESTAMO']['amount'])->toBe(50000.0);
});

it('LA PRUEBA DEL SALDO: el control de préstamos comprueba el saldo contra lo aplicado', function () {
    $f = payrollReportsFixture();

    $rows = runPayrollReport('loans-savings', $f)->rows;
    $loan = $rows[0];

    // Una cuota aplicada de ₡50.000 sobre ₡600.000 originales.
    expect($loan['applied'])->toBe(50000.0)
        ->and($loan['balance'])->toBe(550000.0)
        ->and($loan['expected_balance'])->toBe(550000.0)
        // La diferencia en cero es el punto del reporte: el saldo es un campo
        // que se mantiene, y un campo que se mantiene puede quedar mal.
        ->and($loan['difference'])->toBe(0.0);
});

it('el control de préstamos delata un saldo descuadrado', function () {
    $f = payrollReportsFixture();

    // Alguien lo movió a mano: es exactamente lo que hay que poder encontrar.
    EmployeeDeduction::where('reference', 'PR-1')->update(['balance' => '100000.00']);

    $result = runPayrollReport('loans-savings', $f, ['only_mismatched' => true]);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]['difference'])->toBe(-450000.0)
        ->and(implode(' ', $result->notes))->toContain('descuadrado');
});

it('LA PRUEBA DEL PASIVO: el saldo de vacaciones se valora en colones', function () {
    $f = payrollReportsFixture();

    $rows = collect(runPayrollReport('vacation-balance', $f)->rows)->keyBy('code');

    // R2 acumuló 14 días a mano más 1 día del cálculo de la planilla.
    expect($rows['R2']['balance'])->toBe(15.0)
        // ₡450.000 ÷ 30 = ₡15.000 el día.
        ->and($rows['R2']['daily_rate'])->toBe(15000.0)
        ->and($rows['R2']['liability'])->toBe(225000.0);
});

it('la tarjeta de vacaciones lleva el saldo corriendo y lo reinicia por trabajador', function () {
    $f = payrollReportsFixture();

    $rows = runPayrollReport('vacation-movements', $f)->rows;

    // Cada trabajador arranca su propio saldo: si no se reiniciara, el
    // segundo arrancaría con el saldo del primero.
    $firstOfEach = collect($rows)->groupBy('employee_code')->map(fn ($g) => $g->first());

    foreach ($firstOfEach as $first) {
        expect($first['running_balance'])->toBe($first['days']);
    }

    // Y la última fila de R2 es su saldo real.
    $r2 = collect($rows)->where('employee_code', 'R2')->last();

    expect($r2['running_balance'])->toBe(15.0);
});

it('la tarjeta avisa cuando el saldo corrido no es el real por un filtro', function () {
    $f = payrollReportsFixture();

    $notes = implode(' ', runPayrollReport('vacation-movements', $f, ['from' => '2026-03-01'])->notes);

    expect($notes)->toContain('NO coincide con el saldo real');
});

it('las acciones de personal llevan el valor anterior y el nuevo', function () {
    $f = payrollReportsFixture();

    $rows = runPayrollReport('personnel-actions', $f, ['from' => '2026-01-01'])->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['previous_value'])->toBe('700000.00')
        ->and($rows[0]['new_value'])->toBe('800000.00');
});

it('LA PRUEBA DEL RECARGO: el costo patronal lo expresa como porcentaje del bruto', function () {
    $f = payrollReportsFixture();

    $result = runPayrollReport('employer-cost', $f, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    $row = $result->rows[0];

    // Cargas patronales más provisiones sobre el bruto: en Costa Rica ronda el
    // 45%, y es la cifra con la que hay que cotizar.
    expect($row['surcharge'])->toBeGreaterThan(30.0)
        ->and(round($row['total_cost'], 2))
        ->toBe(round($row['gross'] + $row['employer_contributions'] + $row['provisions'], 2))
        // Cuenta personas, no boletas.
        ->and($row['employees'])->toBe(2);
});

it('el recargo del conjunto no es el promedio de los recargos', function () {
    $f = payrollReportsFixture();

    $result = runPayrollReport('employer-cost', $f, ['from' => '2026-01-01', 'to' => '2026-12-31']);

    // Es un total declarado por el reporte: sumar una columna de porcentajes
    // daría un número sin sentido.
    expect($result->computedTotals())->toHaveKey('surcharge');
});

it('el pasivo laboral acumula las provisiones y resta lo liquidado', function () {
    $f = payrollReportsFixture();

    $rows = collect(runPayrollReport('provisions', $f, ['as_of' => '2026-12-31'])->rows)->keyBy('code');

    expect($rows['R1']['christmas_bonus'])->toBeGreaterThan(0)
        ->and($rows['R1']['vacation'])->toBeGreaterThan(0)
        ->and($rows['R1']['severance'])->toBeGreaterThan(0)
        ->and(round($rows['R1']['outstanding'], 2))
        ->toBe(round($rows['R1']['accrued'] - $rows['R1']['settled'], 2));
});

it('ningún reporte cruza compañías', function () {
    $f = payrollReportsFixture();
    $otra = payrollReportsFixture();

    // La segunda compañía tiene sus propios dos trabajadores, y solo esos.
    $codes = array_column(runPayrollReport('employees', $otra)->rows, 'code');

    expect($codes)->toHaveCount(2);

    // Y los ids de empleado no se mezclan: los de la primera no aparecen en la
    // planilla de la segunda.
    $names = array_column(runPayrollReport('payroll-register', $otra)->rows, 'code');

    expect($names)->toHaveCount(2)
        ->and($f['employees']['R1']->id)->not->toBe($otra['employees']['R1']->id);
});
