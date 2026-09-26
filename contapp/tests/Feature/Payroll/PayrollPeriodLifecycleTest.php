<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\EmployeeDeduction;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollPeriodEvent;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Services\CalculatePayrollService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PostPayrollService;

/**
 * Deshacer una planilla: reabrir la aprobada, anular la contabilizada.
 *
 * Nunca editar ni borrar. Fixture propio del archivo — los helpers de Pest
 * comparten un espacio de nombres global.
 */
function lifecycleFixture(): array
{
    ['company' => $company] = logInAsCompanyUser();

    app(CurrentCompany::class)->set($company->id);

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '500.000000',
    ]);

    $year = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);

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
        'company_id' => $company->id, 'code' => 'PLA', 'origin_module' => 'planilla',
    ]);

    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'expense', 'accepts_posting' => true,
    ]);
    $liability = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'liability', 'accepts_posting' => true,
    ]);

    PayrollSetting::create([
        'company_id' => $company->id,
        'salary_expense_account_id' => $expense->id,
        'net_payable_account_id' => $liability->id,
        'income_tax_payable_account_id' => $liability->id,
        'document_type_id' => $documentType->id,
        'vacation_days_per_month' => '1',
        'max_deduction_percentage' => '0',
    ]);

    PayrollContribution::where('company_id', $company->id)->update([
        'expense_account_id' => $expense->id, 'liability_account_id' => $liability->id,
    ]);
    PayrollProvision::where('company_id', $company->id)->update([
        'expense_account_id' => $expense->id, 'liability_account_id' => $liability->id,
    ]);

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'expense', 'liability', 'costCenter');
}

function lifecycleEmployee(array $f): Employee
{
    static $n = 0;
    $n++;

    return Employee::create([
        'company_id' => $f['company']->id,
        'code' => "L{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '6'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'ccss_number' => "SEG-L{$n}",
        'first_name' => 'Ciclo',
        'last_name1' => "Vida{$n}",
        'hire_date' => '2020-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '800000.00',
        'weekly_hours' => '48',
        'payment_method' => 'efectivo',
        'status' => 'active',
    ]);
}

function lifecyclePeriod(array $f): PayrollPeriod
{
    return PayrollPeriod::create([
        'company_id' => $f['company']->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => 5, 'name' => 'Mayo 2026',
        'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'payment_date' => '2026-05-31',
        'status' => 'open',
    ]);
}

function calculateAndPost(array $f, PayrollPeriod $period): PayrollPeriod
{
    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);
    app(PostPayrollService::class)->post($f['company'], $period->fresh());

    return $period->fresh();
}

// ── Reabrir ──────────────────────────────────────────────────────────────

it('reabre una planilla aprobada y limpia la aprobación anterior', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = lifecyclePeriod($f);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);
    $this->post(route('payroll-periods.approve', $period->id))->assertSessionHasNoErrors();

    expect($period->fresh()->approved_by)->not->toBeNull();

    $this->post(route('payroll-periods.reopen', $period->id), [
        'reason' => 'Faltaron las horas extra de producción',
    ])->assertSessionHasNoErrors();

    $reopened = $period->fresh();

    // La firma anterior se limpia: dejarla diría que alguien aprobó unos
    // números que ya no existen.
    expect($reopened->status)->toBe('calculated')
        ->and($reopened->approved_by)->toBeNull()
        ->and($reopened->approved_at)->toBeNull();
});

it('exige un motivo para reabrir', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = lifecyclePeriod($f);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);
    $this->post(route('payroll-periods.approve', $period->id));

    $this->post(route('payroll-periods.reopen', $period->id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($period->fresh()->status)->toBe('approved');
});

it('no reabre una planilla contabilizada: esa se anula', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = calculateAndPost($f, lifecyclePeriod($f));

    $this->post(route('payroll-periods.reopen', $period->id), ['reason' => 'Me equivoqué'])
        ->assertSessionHasErrors('payroll');

    expect($period->fresh()->status)->toBe('posted');
});

// ── Anular ───────────────────────────────────────────────────────────────

it('anula una planilla contabilizada con asiento de reversión', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = calculateAndPost($f, lifecyclePeriod($f));

    $original = $period->journal_entry_id;

    $this->post(route('payroll-periods.void', $period->id), [
        'reason' => 'Se contabilizó con el salario viejo',
    ])->assertSessionHasNoErrors();

    $voided = $period->fresh();

    // El período vuelve a estar abierto para rehacerlo con sus mismas fechas.
    expect($voided->status)->toBe('open')
        ->and($voided->reversal_journal_entry_id)->not->toBeNull();

    // El asiento original NO se borra: queda anulado.
    $originalEntry = JournalEntry::find($original);
    expect($originalEntry)->not->toBeNull()
        ->and($originalEntry->status)->toBe('voided');

    // Y la reversión cancela exactamente al original.
    $sum = fn (int $id, string $column) => JournalDetail::where('journal_entry_id', $id)
        ->get()->reduce(fn ($c, $d) => bcadd($c, (string) $d->{$column}, 2), '0.00');

    expect($sum($voided->reversal_journal_entry_id, 'debit_local'))->toBe($sum($original, 'credit_local'))
        ->and($sum($voided->reversal_journal_entry_id, 'credit_local'))->toBe($sum($original, 'debit_local'));
});

it('al anular devuelve el saldo del préstamo y quita las vacaciones acreditadas', function () {
    $f = lifecycleFixture();
    $employee = lifecycleEmployee($f);

    $loan = EmployeeDeduction::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'type' => 'loan',
        'description' => 'Préstamo',
        'start_date' => '2026-01-01',
        'original_amount' => '200000',
        'balance' => '200000',
        'calculation' => 'amount',
        'installment_amount' => '40000',
        'priority' => 60,
        'status' => 'active',
    ]);

    $period = calculateAndPost($f, lifecyclePeriod($f));

    expect((string) $loan->fresh()->balance)->toBe('160000.00')
        ->and($employee->fresh()->vacationBalance())->toBe('1.0000');

    $this->post(route('payroll-periods.void', $period->id), [
        'reason' => 'Error en la cuota del préstamo',
    ])->assertSessionHasNoErrors();

    // Sin esto, la planilla corregida rebajaría la cuota dos veces y
    // acreditaría los días otra vez.
    expect((string) $loan->fresh()->balance)->toBe('200000.00')
        ->and($employee->fresh()->vacationBalance())->toBe('0.0000')
        ->and(PayrollEntry::where('payroll_period_id', $period->id)->count())->toBe(0);
});

it('deja rehacer y volver a contabilizar el período anulado', function () {
    $f = lifecycleFixture();
    $employee = lifecycleEmployee($f);
    $period = calculateAndPost($f, lifecyclePeriod($f));

    $this->post(route('payroll-periods.void', $period->id), ['reason' => 'Salario equivocado']);

    // Se corrige lo que estaba mal y se vuelve a correr, con el MISMO
    // período: es la quincena de mayo, no otra.
    $employee->update(['base_salary' => '900000.00']);

    $this->post(route('payroll-periods.calculate', $period->id))->assertSessionHasNoErrors();
    $this->post(route('payroll-periods.post', $period->id))->assertSessionHasNoErrors();

    $redone = $period->fresh();

    expect($redone->status)->toBe('posted')
        ->and($redone->journal_entry_id)->not->toBeNull()
        // El vínculo a la reversión se conserva: es parte de la historia.
        ->and($redone->reversal_journal_entry_id)->not->toBeNull()
        ->and(PayrollEntry::where('payroll_period_id', $period->id)->firstOrFail()->total_earnings)
        ->toBe('900000.00');
});

it('exige un motivo para anular', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = calculateAndPost($f, lifecyclePeriod($f));

    $this->post(route('payroll-periods.void', $period->id), ['reason' => 'no'])
        ->assertSessionHasErrors('reason');

    expect($period->fresh()->status)->toBe('posted');
});

it('no anula una planilla que no se ha contabilizado', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = lifecyclePeriod($f);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);

    $this->post(route('payroll-periods.void', $period->id), ['reason' => 'No hay nada que anular'])
        ->assertSessionHasErrors('payroll');
});

// ── La bitácora ──────────────────────────────────────────────────────────

it('deja en la bitácora quién deshizo la planilla y por qué', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = calculateAndPost($f, lifecyclePeriod($f));

    $this->post(route('payroll-periods.void', $period->id), [
        'reason' => 'El centro de costo estaba mal asignado',
    ])->assertSessionHasNoErrors();

    $event = PayrollPeriodEvent::where('payroll_period_id', $period->id)->firstOrFail();

    expect($event->event)->toBe('voided')
        ->and($event->from_status)->toBe('posted')
        ->and($event->to_status)->toBe('open')
        ->and($event->reason)->toBe('El centro de costo estaba mal asignado')
        ->and($event->created_by)->not->toBeNull()
        // El asiento de reversión queda ligado al evento que lo produjo.
        ->and($event->journal_entry_id)->toBe($period->fresh()->reversal_journal_entry_id);
});

it('la pantalla del período muestra la bitácora', function () {
    $f = lifecycleFixture();
    lifecycleEmployee($f);
    $period = lifecyclePeriod($f);

    app(CalculatePayrollService::class)->calculate($f['company'], $period, []);
    $this->post(route('payroll-periods.approve', $period->id));
    $this->post(route('payroll-periods.reopen', $period->id), ['reason' => 'Faltaba un empleado']);

    $this->get(route('payroll-periods.show', $period->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.event', 'reopened')
            ->where('events.0.reason', 'Faltaba un empleado'));
});
