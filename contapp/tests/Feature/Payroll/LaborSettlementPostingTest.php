<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\JournalDetail;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\PersonnelAction;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Services\CalculateSettlementService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PostSettlementService;

/**
 * Contabilizar y anular una liquidación laboral.
 *
 * Lo que se prueba acá, sobre todo, es que el asiento CANCELE las provisiones
 * en vez de volver a cargar el gasto. Ese es el error grande de una
 * liquidación contabilizada a mano: registra el mismo costo dos veces y deja
 * la provisión inflada creciendo año con año.
 *
 * Fixture propio del archivo — los helpers de Pest comparten un espacio de
 * nombres global.
 */
function settlementPostingFixture(): array
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
        'company_id' => $company->id, 'code' => 'LIQ', 'origin_module' => 'planilla',
    ]);

    app(CostaRicaPayrollDefaults::class)->load($company, '2020-01-01');

    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'expense', 'accepts_posting' => true,
    ]);
    $liability = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'account_type' => 'liability', 'accepts_posting' => true,
    ]);
    // El pasivo de las provisiones va aparte para poder comprobar que el
    // asiento lo DEBITA: con una sola cuenta de pasivo la prueba pasaría
    // igual estando mal.
    $provisionLiability = ChartOfAccount::factory()->create([
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
        'expense_account_id' => $expense->id, 'liability_account_id' => $provisionLiability->id,
    ]);

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'expense', 'liability', 'provisionLiability', 'costCenter');
}

function postingEmployee(array $f, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "P{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '7'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Liquida',
        'last_name1' => "Cion{$n}",
        'hire_date' => '2018-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'salary_type' => 'mensual',
        'base_salary' => '600000.00',
        'weekly_hours' => '48',
        'payment_method' => 'efectivo',
        'status' => 'active',
    ], $attributes));
}

function postedSettlement(array $f, Employee $employee, string $reason = 'despido_con_responsabilidad'): LaborSettlement
{
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '12.0000',
    ]);

    $settlement = LaborSettlement::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'termination_date' => '2026-08-31',
        'reason' => $reason,
        'status' => 'draft',
    ]);

    app(CalculateSettlementService::class)->calculate($f['company'], $settlement);

    return app(PostSettlementService::class)->post($f['company'], $settlement->refresh());
}

// ─────────────────────────────────────────────────────────────────────────

it('debita la provisión de cada extremo en vez de volver a cargar el gasto', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    $details = JournalDetail::where('journal_entry_id', $settlement->journal_entry_id)->get();

    $provisionDebit = $details->where('account_id', $f['provisionLiability']->id)
        ->reduce(fn ($c, JournalDetail $d) => bcadd($c, (string) $d->debit_local, 2), '0.00');

    // Aguinaldo + vacaciones + cesantía van contra el pasivo provisionado: el
    // gasto ya se reconoció mes a mes en cada planilla.
    $provisioned = $settlement->lines
        ->whereIn('kind', ['christmas_bonus', 'vacation', 'severance'])
        ->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 2), '0.00');

    expect($provisionDebit)->toBe($provisioned)
        ->and(bccomp($provisioned, '0.00', 2))->toBe(1);
});

it('lleva el preaviso al gasto, porque ese no se provisiona', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    $notice = $settlement->lines->firstWhere('kind', 'notice');

    $expenseDebit = JournalDetail::where('journal_entry_id', $settlement->journal_entry_id)
        ->where('account_id', $f['expense']->id)
        ->sum('debit_local');

    // La provisión de preaviso está en 0% porque solo existe cuando hay
    // despido sin aviso: es gasto del período en que ocurre.
    expect((string) $notice->amount)->not->toBe('0.00')
        ->and(bccomp((string) $expenseDebit, (string) $notice->amount, 2))->toBe(0);
});

it('el asiento cuadra y deja el neto como pasivo', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    $details = JournalDetail::where('journal_entry_id', $settlement->journal_entry_id)->get();

    $debit = $details->reduce(fn ($c, JournalDetail $d) => bcadd($c, (string) $d->debit_local, 2), '0.00');
    $credit = $details->reduce(fn ($c, JournalDetail $d) => bcadd($c, (string) $d->credit_local, 2), '0.00');

    expect($debit)->toBe($credit);

    // El banco NO se toca: el neto queda debido y el pago es otro asiento.
    $netCredit = $details->where('account_id', $f['liability']->id)
        ->reduce(fn ($c, JournalDetail $d) => bcadd($c, (string) $d->credit_local, 2), '0.00');

    expect(bccomp($netCredit, (string) $settlement->total_net, 2))->toBeGreaterThanOrEqual(0);
});

it('descarga los días de vacaciones pagados y deja el saldo en cero', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    // Dejarlos en el saldo haría que una segunda liquidación los volviera a
    // pagar.
    expect($employee->fresh()->vacationBalance())->toBe('0.0000');

    $movement = VacationMovement::where('labor_settlement_id', $settlement->id)->firstOrFail();

    expect($movement->type)->toBe('settlement')
        ->and((string) $movement->days)->toBe('-12.0000');
});

it('inactiva al trabajador con su fecha de salida y lo deja en la bitácora', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    postedSettlement($f, $employee);

    $fresh = $employee->fresh();

    expect($fresh->status)->toBe('terminated')
        ->and($fresh->termination_date->format('Y-m-d'))->toBe('2026-08-31');

    $action = PersonnelAction::where('employee_id', $employee->id)
        ->where('action_type', 'termination')->firstOrFail();

    expect($action->status)->toBe('applied');
});

it('anular contabiliza la reversión exacta y devuelve los días', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);
    $original = $settlement->journal_entry_id;

    $voided = app(PostSettlementService::class)->void(
        $f['company'], $settlement->refresh(), 'La salida se registró con una causal equivocada.'
    );

    expect($voided->status)->toBe('voided');

    // La reversión tiene que cancelar el original exactamente: cada débito
    // del original es un crédito de la reversión.
    $originalDetails = JournalDetail::where('journal_entry_id', $original)->get();
    $reversalDetails = JournalDetail::where('journal_entry_id', '!=', $original)
        ->whereIn('account_id', $originalDetails->pluck('account_id'))
        ->get();

    $originalDebit = $originalDetails->reduce(fn ($c, JournalDetail $d) => bcadd($c, (string) $d->debit_local, 2), '0.00');
    $reversalCredit = $reversalDetails->reduce(fn ($c, JournalDetail $d) => bcadd($c, (string) $d->credit_local, 2), '0.00');

    expect($reversalCredit)->toBe($originalDebit);

    // Y el trabajador vuelve a estar como estaba: con sus días y activo.
    $fresh = $employee->fresh();

    expect($fresh->status)->toBe('active')
        ->and($fresh->termination_date)->toBeNull()
        ->and($fresh->vacationBalance())->toBe('12.0000');
});

it('exige un motivo para anular', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    expect(fn () => app(PostSettlementService::class)->void($f['company'], $settlement->refresh(), '  '))
        ->toThrow(InvalidPayrollException::class);
});

it('no contabiliza dos veces la misma liquidación', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    expect(fn () => app(PostSettlementService::class)->post($f['company'], $settlement->refresh()))
        ->toThrow(InvalidPayrollException::class);
});

// ── La pantalla ─────────────────────────────────────────────────────────

it('crea, aprueba, contabiliza y anula desde la pantalla', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $this->get(route('labor-settlements.index'))->assertOk();

    $this->post(route('labor-settlements.store'), [
        'employee_id' => $employee->id,
        'termination_date' => '2026-08-31',
        'reason' => 'despido_con_responsabilidad',
        'reason_detail' => 'Reorganización del área.',
    ])->assertSessionHasNoErrors();

    $settlement = LaborSettlement::where('employee_id', $employee->id)->firstOrFail();

    expect($settlement->status)->toBe('draft')
        ->and($settlement->lines()->count())->toBeGreaterThan(0);

    $this->get(route('labor-settlements.show', $settlement->id))->assertOk();

    $this->post(route('labor-settlements.approve', $settlement->id))->assertSessionHasNoErrors();
    expect($settlement->fresh()->status)->toBe('approved');

    $this->post(route('labor-settlements.post', $settlement->id))->assertSessionHasNoErrors();
    expect($settlement->fresh()->status)->toBe('posted');

    $this->post(route('labor-settlements.void', $settlement->id), [
        'reason' => 'Se dejó sin efecto el despido.',
    ])->assertSessionHasNoErrors();

    expect($settlement->fresh()->status)->toBe('voided');
});

it('no deja dos liquidaciones vivas del mismo trabajador', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $payload = [
        'employee_id' => $employee->id,
        'termination_date' => '2026-08-31',
        'reason' => 'renuncia',
    ];

    $this->post(route('labor-settlements.store'), $payload)->assertSessionHasNoErrors();

    // Dos borradores del mismo caso terminan contabilizándose los dos.
    $this->post(route('labor-settlements.store'), $payload)->assertSessionHasErrors('employee_id');

    expect(LaborSettlement::where('employee_id', $employee->id)->count())->toBe(1);
});

it('no permite eliminar una liquidación contabilizada', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $settlement = postedSettlement($f, $employee);

    $this->delete(route('labor-settlements.destroy', $settlement->id))->assertSessionHasErrors('payroll');

    expect($settlement->fresh())->not->toBeNull();
});

it('elimina una en borrador, que nunca llegó a la contabilidad', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $this->post(route('labor-settlements.store'), [
        'employee_id' => $employee->id,
        'termination_date' => '2026-08-31',
        'reason' => 'renuncia',
    ]);

    $settlement = LaborSettlement::where('employee_id', $employee->id)->firstOrFail();

    $this->delete(route('labor-settlements.destroy', $settlement->id))->assertSessionHasNoErrors();

    expect(LaborSettlement::find($settlement->id))->toBeNull();
});

it('devuelve a borrador una aprobada, con motivo', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $this->post(route('labor-settlements.store'), [
        'employee_id' => $employee->id,
        'termination_date' => '2026-08-31',
        'reason' => 'renuncia',
    ]);

    $settlement = LaborSettlement::where('employee_id', $employee->id)->firstOrFail();

    $this->post(route('labor-settlements.approve', $settlement->id));

    $this->post(route('labor-settlements.reopen', $settlement->id), [])
        ->assertSessionHasErrors('reason');

    $this->post(route('labor-settlements.reopen', $settlement->id), [
        'reason' => 'Faltaba agregar el saldo del préstamo.',
    ])->assertSessionHasNoErrors();

    expect($settlement->fresh()->status)->toBe('draft')
        ->and($settlement->fresh()->approved_at)->toBeNull();
});

it('recalcula agregando una deducción desde la pantalla', function () {
    $f = settlementPostingFixture();
    $employee = postingEmployee($f);

    $this->post(route('labor-settlements.store'), [
        'employee_id' => $employee->id,
        'termination_date' => '2026-08-31',
        'reason' => 'renuncia',
    ]);

    $settlement = LaborSettlement::where('employee_id', $employee->id)->firstOrFail();

    $this->post(route('labor-settlements.calculate', $settlement->id), [
        'lines' => [
            ['kind' => 'deduction', 'code' => 'PRESTAMO', 'name' => 'Saldo de préstamo', 'amount' => '75000'],
        ],
    ])->assertSessionHasNoErrors();

    expect($settlement->fresh()->total_other_deductions)->toBe('75000.00');
});
