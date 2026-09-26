<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\DataTransferObjects\PayrollInputLine;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PostPayrollService;

/**
 * El fixture es propio de este archivo a propósito: los helpers de Pest
 * viven en un único espacio de nombres global y tomar uno de otro archivo
 * ata este test a que ese archivo se cargue también.
 */
function payrollFixture(): array
{
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);

    // El asiento de planilla se contabiliza en colones, pero el motor
    // contable deriva siempre las tres monedas y para eso necesita un tipo
    // de cambio vigente.
    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '500.000000',
    ]);

    $year = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

    // Se abren los doce períodos: la planilla se contabiliza con la fecha de
    // PAGO, que puede caer en el mes siguiente al trabajado.
    foreach (range(1, 12) as $month) {
        FiscalPeriod::factory()->create([
            'fiscal_year_id' => $year->id,
            'period_number' => $month,
            'start_date' => "2026-{$month}-01",
            'end_date' => date('Y-m-t', strtotime("2026-{$month}-01")),
            'status' => 'open',
        ]);
    }

    $documentType = DocumentType::factory()->create([
        'company_id' => $company->id,
        'code' => 'PLA',
        'origin_module' => 'planilla',
    ]);

    $account = fn (string $type) => ChartOfAccount::factory()
        ->create(['company_id' => $company->id, 'account_type' => $type]);

    $expense = $account('expense');
    $liability = $account('liability');

    $settings = PayrollSetting::create([
        'company_id' => $company->id,
        'salary_expense_account_id' => $expense->id,
        'net_payable_account_id' => $liability->id,
        'income_tax_payable_account_id' => $account('liability')->id,
        'document_type_id' => $documentType->id,
        'vacation_days_per_month' => '1',
        'max_deduction_percentage' => '0',
    ]);

    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    // Las cargas y provisiones necesitan sus dos cuentas para poder
    // contabilizarse; la plantilla las deja vacías a propósito.
    PayrollContribution::where('company_id', $company->id)->update([
        'expense_account_id' => $expense->id,
        'liability_account_id' => $liability->id,
    ]);
    PayrollProvision::where('company_id', $company->id)->update([
        'expense_account_id' => $expense->id,
        'liability_account_id' => $liability->id,
    ]);

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'documentType', 'settings', 'costCenter', 'expense', 'liability');
}

function payrollEmployee(array $f, array $attributes = []): Employee
{
    static $sequence = 0;
    $sequence++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "EMP-{$sequence}",
        'identification_type' => 'cedula',
        'identification_number' => '1'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Trabajador',
        'last_name1' => "Número{$sequence}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '1000000.00',
        'status' => 'active',
    ], $attributes));
}

function payrollPeriod(array $f, array $attributes = []): PayrollPeriod
{
    return PayrollPeriod::create(array_merge([
        'company_id' => $f['company']->id,
        'year' => 2026,
        'frequency' => 'mensual',
        'number' => 3,
        'name' => 'Marzo 2026',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'payment_date' => '2026-03-31',
        'status' => 'open',
    ], $attributes));
}

function calculatePayroll(array $f, PayrollPeriod $period, array $inputs = []): PayrollPeriod
{
    return app(CalculatePayrollService::class)->calculate($f['company'], $period, $inputs);
}

// ─────────────────────────────────────────────────────────────────────────

it('rebaja cada componente de carga social por separado y contra la base correcta', function () {
    $f = payrollFixture();
    payrollEmployee($f);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // 5,50 + 4,17 + 1,00 = 10,67% de un millón.
    expect($entry->total_employee_contributions)->toBe('106700.00')
        ->and($entry->ccss_base)->toBe('1000000.00');

    // Cada componente deja su propia línea con su tasa congelada: es lo que
    // permite conciliar la planilla de la Caja renglón por renglón.
    $lines = $entry->lines->where('kind', 'employee_contribution');

    expect($lines)->toHaveCount(3)
        ->and($lines->firstWhere('code', 'IVM-OBR')->rate)->toBe('4.1700')
        ->and($lines->firstWhere('code', 'IVM-OBR')->base_amount)->toBe('1000000.00');
});

it('aplica la escala sobre el salario devengado, que es el criterio por defecto', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['base_salary' => '1500000.00']);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // Si la escala se aplica sobre el devengado o sobre el devengado menos
    // las cargas obreras es una cuestión de la Ley del Impuesto sobre la
    // Renta, no del motor. Por eso es un parámetro, y este es su valor por
    // defecto: el devengado completo.
    expect($entry->income_tax_base)->toBe('1500000.00');
});

it('puede aplicar la escala sobre el devengado menos las cargas obreras', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['base_salary' => '1500000.00']);

    PayrollSetting::where('company_id', $f['company']->id)
        ->update(['income_tax_base' => 'net_of_contributions']);

    $period = payrollPeriod($f);
    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // 1.500.000 − 10,67% de cargas obreras = 1.339.950.
    expect($entry->income_tax_base)->toBe('1339950.00');
});

it('grava cada tramo por separado y no todo el salario a la tasa del tramo superior', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['base_salary' => '3000000.00', 'is_ccss_exempt' => true]);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    //   0 – 929.000           exento
    //   929.000 – 1.363.000   10%  →  43.400
    //   1.363.000 – 2.392.000 15%  → 154.350
    //   2.392.000 – 3.000.000 20%  → 121.600
    //                               ─────────
    //                                 319.350
    expect($entry->income_tax)->toBe('319350.00');

    // El error clásico —20% sobre los tres millones— daría 600.000.
    expect($entry->income_tax)->not->toBe('600000.00');
});

it('resta los créditos familiares del impuesto y no de la base', function () {
    $f = payrollFixture();
    payrollEmployee($f, [
        'base_salary' => '1500000.00',
        'has_spouse_credit' => true,
        'children_credit_count' => 2,
    ]);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // La base no cambia por tener familia: los créditos actúan después.
    expect($entry->income_tax_base)->toBe('1500000.00');

    //   929.000 – 1.363.000  10%  →  43.400
    //   1.363.000 – 1.500.000 15% →  20.550
    //                              ─────────
    //                                63.950
    $taxBeforeCredits = bcadd(
        bcdiv(bcmul(bcsub('1363000', '929000', 2), '10', 4), '100', 2),
        bcdiv(bcmul(bcsub('1500000', '1363000', 2), '15', 4), '100', 2),
        2
    );

    $credits = bcadd('4000', bcmul('2600', '2', 2), 2); // cónyuge + 2 hijos

    expect($entry->income_tax)->toBe(bcsub($taxBeforeCredits, $credits, 2));
});

it('nunca devuelve impuesto: el crédito que sobra se pierde, no genera un pago al trabajador', function () {
    $f = payrollFixture();
    payrollEmployee($f, [
        'base_salary' => '950000.00',
        'has_spouse_credit' => true,
        'children_credit_count' => 5,
    ]);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    expect($entry->income_tax)->toBe('0.00')
        ->and(bccomp($entry->net_pay, '0.00', 2))->toBe(1);
});

it('deja los viáticos fuera de la base de cargas y del impuesto', function () {
    $f = payrollFixture();
    $employee = payrollEmployee($f);
    $period = payrollPeriod($f);

    $viatico = PayrollConcept::where('company_id', $f['company']->id)->where('code', 'VIATICO')->firstOrFail();

    calculatePayroll($f, $period, [
        new PayrollInputLine($employee->id, $viatico->id, amount: '200000'),
    ]);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // El viático engrosa lo que el trabajador recibe, pero no es salario:
    // no entra a la base de cargas ni a la del impuesto.
    expect($entry->total_earnings)->toBe('1200000.00')
        ->and($entry->ccss_base)->toBe('1000000.00')
        ->and($entry->total_employee_contributions)->toBe('106700.00');
});

it('paga las horas extra al factor del concepto sobre el valor de la hora ordinaria', function () {
    $f = payrollFixture();
    // Jornada de 48 horas semanales sobre un salario de ₡1.000.000.
    $employee = payrollEmployee($f, ['weekly_hours' => '48']);
    $period = payrollPeriod($f);

    $extra = PayrollConcept::where('company_id', $f['company']->id)->where('code', 'HE-SIMPLE')->firstOrFail();

    calculatePayroll($f, $period, [
        new PayrollInputLine($employee->id, $extra->id, quantity: 10),
    ]);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();
    $line = $entry->lines->firstWhere('code', 'HE-SIMPLE');

    // El valor de la hora sale del DÍA, no de la semana: el salario mensual
    // cubre los 30 días del mes —incluido el día de descanso, que es pagado—
    // y la jornada diurna tiene 8 horas ordinarias. Son 240 horas al mes,
    // que es también lo que suman dos quincenas de 120.
    //
    //   1.000.000 ÷ 30 = 33.333,33 el día
    //   33.333,33 ÷ 8  =  4.166,67 la hora
    //   4.166,67 × 10 × 1,5 = 62.500,00
    //
    // Dividir entre las horas semanales por 4,3333 daba 72.115,93: un 15% de
    // más en cada hora extra, por suponer que el día de descanso no se paga.
    expect($line->amount)->toBe('62500.00');

    // Y sí son salario: entran completas a la base de cargas.
    expect($entry->ccss_base)->toBe('1062500.00');
});

it('paga proporcional a quien ingresa a mitad de período en vez de excluirlo o pagarle completo', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['hire_date' => '2026-03-17']);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // Mes convencional de 30 días: del 17 al 30 son 14, no 15. El día 31 no
    // existe en el mes de planilla, y por eso un mes trabajado completo paga
    // siempre 30 días — en agosto igual que en febrero.
    expect($entry->days_worked)->toBe('14.00')
        ->and($entry->total_earnings)->toBe('466666.67');
});

it('cuenta las dos quincenas como quince días, sin importar los días del mes', function () {
    $f = payrollFixture();
    $ingresa = payrollEmployee($f, ['hire_date' => '2026-08-20']);
    $sale = payrollEmployee($f, ['termination_date' => '2026-08-20', 'status' => 'active']);
    $completo = payrollEmployee($f);

    // Agosto tiene 31 días; la segunda quincena va del 16 al 31.
    $segunda = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 16, 'name' => 'Agosto 2026 · 2.ª quincena',
        'start_date' => '2026-08-16', 'end_date' => '2026-08-31', 'payment_date' => '2026-08-31',
    ]);

    calculatePayroll($f, $segunda);

    $dias = PayrollEntry::where('payroll_period_id', $segunda->id)
        ->get()->keyBy('employee_id')->map(fn ($e) => $e->days_worked);

    // Quien ingresó el 20 cobra del 20 al 30: once días.
    expect($dias[$ingresa->id])->toBe('11.00')
        // Quien salió el 20 cobra del 16 al 20: cinco.
        ->and($dias[$sale->id])->toBe('5.00')
        // Y la quincena completa son quince, aunque el mes tenga 31 días.
        ->and($dias[$completo->id])->toBe('15.00');
});

it('una quincena completa de febrero también paga quince días', function () {
    $f = payrollFixture();
    payrollEmployee($f);

    // Febrero de 2026 termina el 28: sin la convención pagaría 13 días y la
    // misma quincena valdría distinto según el mes.
    $febrero = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 4, 'name' => 'Febrero 2026 · 2.ª quincena',
        'start_date' => '2026-02-16', 'end_date' => '2026-02-28', 'payment_date' => '2026-02-28',
    ]);

    calculatePayroll($f, $febrero);

    $entry = PayrollEntry::where('payroll_period_id', $febrero->id)->firstOrFail();

    expect($entry->days_worked)->toBe('15.00')
        ->and($entry->total_earnings)->toBe('500000.00');
});

it('acumula las dos quincenas: la primera no retiene y la segunda retiene el mes', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['base_salary' => '1500000.00', 'is_ccss_exempt' => true]);

    // Pago mensual con adelantos quincenales: cada quincena devenga 750.000.
    $primera = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 5, 'name' => 'Marzo 2026 · 1.ª quincena',
        'start_date' => '2026-03-01', 'end_date' => '2026-03-15', 'payment_date' => '2026-03-15',
    ]);

    calculatePayroll($f, $primera);

    // 750.000 está por debajo del tramo exento de 929.000: no retiene nada.
    // No hace falta marcar nada; sale de la aritmética.
    $entradaPrimera = PayrollEntry::where('payroll_period_id', $primera->id)->firstOrFail();

    expect($entradaPrimera->income_tax_base)->toBe('750000.00')
        ->and($entradaPrimera->income_tax)->toBe('0.00');

    $segunda = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 6, 'name' => 'Marzo 2026 · 2.ª quincena',
        'start_date' => '2026-03-16', 'end_date' => '2026-03-31', 'payment_date' => '2026-03-31',
    ]);

    calculatePayroll($f, $segunda);

    // En la segunda se suma lo devengado de las dos: 1.500.000. La escala se
    // aplica al mes completo y se retiene la diferencia contra lo ya
    // retenido —que fue cero—, o sea el impuesto entero del mes.
    //
    //   929.000 – 1.363.000  10%  →  43.400
    //   1.363.000 – 1.500.000 15% →  20.550
    //                              ─────────
    //                                63.950
    $impuestoDelMes = bcadd(
        bcdiv(bcmul(bcsub('1363000', '929000', 2), '10', 4), '100', 2),
        bcdiv(bcmul(bcsub('1500000', '1363000', 2), '15', 4), '100', 2),
        2
    );

    $entradaSegunda = PayrollEntry::where('payroll_period_id', $segunda->id)->firstOrFail();

    expect($entradaSegunda->income_tax)->toBe($impuestoDelMes);
});

it('en modo proyectado reparte el impuesto del mes entre las dos quincenas', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['base_salary' => '1500000.00', 'is_ccss_exempt' => true]);

    PayrollSetting::where('company_id', $f['company']->id)
        ->update(['income_tax_mode' => 'projected']);

    $quincena = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 5, 'name' => 'Marzo 2026 · 1.ª quincena',
        'start_date' => '2026-03-01', 'end_date' => '2026-03-15', 'payment_date' => '2026-03-15',
    ]);

    calculatePayroll($f, $quincena);

    // El proyectado multiplica la quincena por dos, aplica la escala y
    // retiene la mitad. Supone que la segunda quincena será igual a la
    // primera: con comisiones concentradas en una sola, retiene de más en
    // una y de menos en la otra.
    $impuestoDelMes = bcadd(
        bcdiv(bcmul(bcsub('1363000', '929000', 2), '10', 4), '100', 2),
        bcdiv(bcmul(bcsub('1500000', '1363000', 2), '15', 4), '100', 2),
        2
    );

    $entry = PayrollEntry::where('payroll_period_id', $quincena->id)->firstOrFail();

    expect($entry->income_tax)->toBe(bcdiv($impuestoDelMes, '2', 2));
});

it('en acumulado no retiene dos veces si la segunda quincena se recalcula', function () {
    $f = payrollFixture();
    payrollEmployee($f, ['base_salary' => '1500000.00', 'is_ccss_exempt' => true]);

    $primera = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 5, 'name' => '1.ª quincena',
        'start_date' => '2026-03-01', 'end_date' => '2026-03-15', 'payment_date' => '2026-03-15',
    ]);
    calculatePayroll($f, $primera);

    $segunda = payrollPeriod($f, [
        'frequency' => 'quincenal', 'number' => 6, 'name' => '2.ª quincena',
        'start_date' => '2026-03-16', 'end_date' => '2026-03-31', 'payment_date' => '2026-03-31',
    ]);

    calculatePayroll($f, $segunda);
    $primerCalculo = PayrollEntry::where('payroll_period_id', $segunda->id)->firstOrFail()->income_tax;

    // Recalcular no puede acumular sobre sí misma: al reunir lo retenido
    // antes solo cuentan los períodos que EMPIEZAN antes que este.
    calculatePayroll($f, $segunda->fresh());

    expect(PayrollEntry::where('payroll_period_id', $segunda->id)->firstOrFail()->income_tax)
        ->toBe($primerCalculo);
});

it('rebaja las obligaciones en orden de prioridad y no deja el neto negativo', function () {
    $f = payrollFixture();
    $employee = payrollEmployee($f, ['base_salary' => '400000.00']);
    $period = payrollPeriod($f);

    // Dos obligaciones que juntas superan con creces el salario.
    EmployeeDeduction::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'loan', 'description' => 'Préstamo bancario',
        'start_date' => '2026-01-01', 'original_amount' => '900000', 'balance' => '900000',
        'calculation' => 'amount', 'installment_amount' => '900000',
        'priority' => 60, 'status' => 'active',
    ]);

    EmployeeDeduction::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'alimony', 'description' => 'Pensión alimentaria',
        'start_date' => '2026-01-01', 'balance' => null,
        'calculation' => 'amount', 'installment_amount' => '100000',
        'priority' => 10, 'status' => 'active',
    ]);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    expect(bccomp($entry->net_pay, '0.00', 2))->toBeGreaterThanOrEqual(0);

    // La pensión tiene preferencia legal: se rebaja completa aunque se
    // hubiera registrado después que el préstamo.
    $alimony = $entry->lines->firstWhere('name', 'Pensión alimentaria');
    expect($alimony)->not->toBeNull()
        ->and($alimony->amount)->toBe('100000.00');

    // El préstamo solo alcanzó a cobrarse lo que cabía, y conserva saldo.
    $loan = EmployeeDeduction::where('type', 'loan')->firstOrFail();
    expect(bccomp((string) $loan->balance, '0.00', 2))->toBe(1);
});

it('devuelve el saldo del préstamo al recalcular en vez de cobrarlo dos veces', function () {
    $f = payrollFixture();
    $employee = payrollEmployee($f);
    $period = payrollPeriod($f);

    $loan = EmployeeDeduction::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'loan', 'description' => 'Préstamo solidarista',
        'start_date' => '2026-01-01', 'original_amount' => '300000', 'balance' => '300000',
        'calculation' => 'amount', 'installment_amount' => '50000',
        'priority' => 60, 'status' => 'active',
    ]);

    calculatePayroll($f, $period);
    expect((string) $loan->fresh()->balance)->toBe('250000.00');

    // Recalcular NO puede volver a rebajar sobre el saldo ya disminuido.
    calculatePayroll($f, $period);
    expect((string) $loan->fresh()->balance)->toBe('250000.00');

    // Y queda un solo rastro del rebajo, no dos.
    expect($loan->fresh()->applications()->count())->toBe(1);
});

it('acredita vacaciones proporcionales al período y no las duplica al recalcular', function () {
    $f = payrollFixture();
    $employee = payrollEmployee($f);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);
    calculatePayroll($f, $period);

    $movements = VacationMovement::where('employee_id', $employee->id)->get();

    expect($movements)->toHaveCount(1);

    // Un mes trabajado completo acredita exactamente un día. Con días
    // calendario daba 1,0333 en marzo y 0,9667 en febrero: el mismo mes
    // trabajado valía distinto según cuántos días tuviera.
    expect((string) $movements->first()->days)->toBe('1.0000');
});

it('cobra el costo patronal encima del salario sin tocar el neto del trabajador', function () {
    $f = payrollFixture();
    payrollEmployee($f);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // Lo patronal es costo de la empresa: no aparece en las deducciones.
    expect(bccomp($entry->total_employer_contributions, '0.00', 2))->toBe(1)
        ->and($entry->total_deductions)->toBe(
            bcadd(bcadd($entry->total_employee_contributions, $entry->income_tax, 2), $entry->total_other_deductions, 2)
        );

    // Y el costo real del puesto es bastante mayor que el salario bruto.
    expect(bccomp($entry->employerCost(), $entry->total_earnings, 2))->toBe(1);
});

it('contabiliza la planilla con un asiento cuadrado, el bruto al gasto y el centro de costo del empleado', function () {
    $f = payrollFixture();
    payrollEmployee($f);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);

    $posted = app(PostPayrollService::class)->post($f['company'], $period->fresh());

    expect($posted->status)->toBe('posted')
        ->and($posted->journal_entry_id)->not->toBeNull();

    $details = JournalDetail::where('journal_entry_id', $posted->journal_entry_id)->get();

    $debit = $details->reduce(fn ($c, $d) => bcadd($c, (string) $d->debit_local, 2), '0.00');
    $credit = $details->reduce(fn ($c, $d) => bcadd($c, (string) $d->credit_local, 2), '0.00');

    expect($debit)->toBe($credit);

    $entry = PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail();

    // El gasto de salarios es el BRUTO, no el neto: las retenciones son
    // dinero del trabajador que la empresa custodia, no gasto propio.
    $salaryLine = $details->firstWhere('account_id', $f['expense']->id);
    expect($salaryLine)->not->toBeNull();

    expect($details->whereNotNull('cost_center_id')->pluck('cost_center_id')->unique()->all())
        ->toBe([$f['costCenter']->id]);

    // El neto queda como pasivo con el trabajador: el banco se toca en el
    // asiento de pago, que es otro hecho.
    $netLine = $details->where('account_id', $f['liability']->id)
        ->firstWhere('credit_local', $entry->net_pay);
    expect($netLine)->not->toBeNull();
});

it('no deja recalcular una planilla ya contabilizada', function () {
    $f = payrollFixture();
    payrollEmployee($f);
    $period = payrollPeriod($f);

    calculatePayroll($f, $period);
    app(PostPayrollService::class)->post($f['company'], $period->fresh());

    calculatePayroll($f, $period->fresh());
})->throws(InvalidPayrollException::class, 'ya no se puede recalcular');

it('reproduce una planilla vieja con las tasas de su fecha, no con las de hoy', function () {
    $f = payrollFixture();
    payrollEmployee($f);

    // A partir de julio sube la cuota de IVM obrero.
    PayrollContribution::where('company_id', $f['company']->id)
        ->where('code', 'IVM-OBR')->update(['valid_to' => '2026-06-30']);

    PayrollContribution::create([
        'company_id' => $f['company']->id, 'code' => 'IVM-OBR',
        'name' => 'CCSS · Invalidez, Vejez y Muerte (obrero)',
        'payer' => 'employee', 'institution' => 'ccss', 'percentage' => '5.17',
        'base' => 'ccss', 'valid_from' => '2026-07-01', 'status' => 'active',
    ]);

    $marzo = payrollPeriod($f);
    calculatePayroll($f, $marzo);

    $agosto = payrollPeriod($f, [
        'number' => 8, 'name' => 'Agosto 2026',
        'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'payment_date' => '2026-08-31',
    ]);
    calculatePayroll($f, $agosto);

    $marzoEntry = PayrollEntry::where('payroll_period_id', $marzo->id)->firstOrFail();
    $agostoEntry = PayrollEntry::where('payroll_period_id', $agosto->id)->firstOrFail();

    expect($marzoEntry->lines->firstWhere('code', 'IVM-OBR')->rate)->toBe('4.1700')
        ->and($agostoEntry->lines->firstWhere('code', 'IVM-OBR')->rate)->toBe('5.1700');
});
