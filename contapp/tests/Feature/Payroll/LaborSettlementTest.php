<?php

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\LaborSettlement;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Models\PayrollSetting;
use App\Domains\Payroll\Models\VacationMovement;
use App\Domains\Payroll\Services\CalculateSettlementService;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;

/**
 * Liquidaciones laborales.
 *
 * Los números de referencia salen del Manual de Liquidaciones Laborales de
 * Costa Rica (edición 2026) que aportó el usuario, y de los artículos 28, 29,
 * 30, 31 y 157 del Código de Trabajo.
 *
 * Fixture propio del archivo: los helpers de Pest comparten un espacio de
 * nombres global.
 */
function settlementFixture(): array
{
    $company = Company::factory()->create();

    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2020-01-01');

    $costCenter = CostCenter::factory()->create(['company_id' => $company->id]);

    return compact('company', 'costCenter');
}

function settlementEmployee(array $f, array $attributes = []): Employee
{
    static $sequence = 0;
    $sequence++;

    return Employee::create(array_merge([
        'company_id' => $f['company']->id,
        'code' => "LIQ-{$sequence}",
        'identification_type' => 'cedula',
        'identification_number' => '2'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT),
        'first_name' => 'Trabajador',
        'last_name1' => "Liquidado{$sequence}",
        'hire_date' => '2015-01-01',
        'cost_center_id' => $f['costCenter']->id,
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => '600000.00',
        'status' => 'active',
    ], $attributes));
}

/**
 * Historial de planilla del trabajador: es de donde salen los promedios, y sin
 * él la liquidación se calcula con la ficha y avisa.
 */
function settlementHistory(array $f, Employee $employee, int $months, string $monthlyEarnings, string $upTo = '2026-08-31'): void
{
    $end = Carbon\Carbon::parse($upTo)->startOfMonth();

    for ($i = 0; $i < $months; $i++) {
        $monthEnd = $end->copy()->subMonths($i)->endOfMonth();

        $period = PayrollPeriod::create([
            'company_id' => $f['company']->id,
            'year' => (int) $monthEnd->format('Y'),
            'frequency' => 'mensual',
            'number' => (int) $monthEnd->format('m'),
            'name' => 'Planilla '.$monthEnd->format('m/Y'),
            'start_date' => $monthEnd->copy()->startOfMonth()->format('Y-m-d'),
            'end_date' => $monthEnd->format('Y-m-d'),
            'payment_date' => $monthEnd->format('Y-m-d'),
            'status' => 'posted',
        ]);

        PayrollEntry::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'days_worked' => 30,
            'base_salary' => $monthlyEarnings,
            'total_earnings' => $monthlyEarnings,
            'ccss_base' => $monthlyEarnings,
            'income_tax_base' => '0.00',
            'total_employee_contributions' => '0.00',
            'income_tax' => '0.00',
            'total_other_deductions' => '0.00',
            'total_deductions' => '0.00',
            'net_pay' => $monthlyEarnings,
            'total_employer_contributions' => '0.00',
            'total_provisions' => '0.00',
        ]);
    }
}

function settlementFor(array $f, Employee $employee, string $reason, string $date = '2026-08-31', array $extra = []): LaborSettlement
{
    $settlement = LaborSettlement::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'termination_date' => $date,
        'reason' => $reason,
        'status' => 'draft',
    ]);

    return app(CalculateSettlementService::class)->calculate($f['company'], $settlement, $extra);
}

function settlementLine(LaborSettlement $settlement, string $kind): mixed
{
    return $settlement->lines->firstWhere('kind', $kind);
}

// ── La tabla del art. 29, antes de cualquier cálculo ─────────────────────

it('acumula los días de cesantía año por año según el art. 29', function () {
    // La escala no es lineal: sube hasta el año 7, se mantiene y vuelve a
    // bajar. Cualquier fórmula inventada para "simplificarla" falla acá.
    expect(LaborSettlement::severanceDaysFor(1, 0)['days'])->toBe('19.5000')
        ->and(LaborSettlement::severanceDaysFor(2, 0)['days'])->toBe('39.5000')
        ->and(LaborSettlement::severanceDaysFor(5, 0)['days'])->toBe('102.2400');
});

it('topa la cesantía en los ocho años', function () {
    // Suma de la escala del año 1 al 8. Alguien con veinte años de servicio
    // cobra exactamente lo mismo que alguien con ocho: es el tope del art. 29
    // y es la parte que más sorprende a quien liquida por primera vez.
    $eight = LaborSettlement::severanceDaysFor(8, 0)['days'];

    expect($eight)->toBe('167.7400')
        ->and(LaborSettlement::severanceDaysFor(20, 0)['days'])->toBe($eight)
        ->and(LaborSettlement::severanceDaysFor(20, 0)['detail'])->toContain('topados');
});

it('cuenta como un año la fracción mayor a seis meses', function () {
    expect(LaborSettlement::severanceDaysFor(2, 7)['days'])
        ->toBe(LaborSettlement::severanceDaysFor(3, 0)['days'])
        ->and(LaborSettlement::severanceDaysFor(2, 5)['days'])
        ->toBe(LaborSettlement::severanceDaysFor(2, 0)['days']);
});

it('aplica la escala del primer año y nada antes de los tres meses', function () {
    expect(LaborSettlement::severanceDaysFor(0, 8)['days'])->toBe('14.0000')
        ->and(LaborSettlement::severanceDaysFor(0, 4)['days'])->toBe('7.0000')
        ->and(LaborSettlement::severanceDaysFor(0, 2)['days'])->toBe('0.0000');
});

it('escala el preaviso por antigüedad según el art. 28', function () {
    expect(LaborSettlement::noticeDaysFor(2)['days'])->toBe('0.0000')
        ->and(LaborSettlement::noticeDaysFor(4)['days'])->toBe('7.0000')
        ->and(LaborSettlement::noticeDaysFor(8)['days'])->toBe('15.0000')
        ->and(LaborSettlement::noticeDaysFor(60)['days'])->toBe('30.0000');
});

// ── Qué se paga según la causal ──────────────────────────────────────────

it('paga los cuatro extremos en un despido con responsabilidad patronal', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');

    expect($settlement->lines->pluck('kind')->all())
        ->toBe(['christmas_bonus', 'vacation', 'notice', 'severance']);
});

it('no paga preaviso ni cesantía en un despido con justa causa, pero sí aguinaldo y vacaciones', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'despido_sin_responsabilidad');

    // Son derechos irrenunciables: se pagan incluso cuando el despido fue con
    // justa causa. Es el error más caro de una liquidación hecha a mano.
    expect($settlement->lines->pluck('kind')->all())->toBe(['christmas_bonus', 'vacation'])
        ->and(settlementLine($settlement, 'severance'))->toBeNull();
});

it('en una renuncia no le paga el preaviso al trabajador y lo advierte', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    $settlement = settlementFor($f, $employee, 'renuncia');

    expect(settlementLine($settlement, 'notice'))->toBeNull();

    $messages = collect(app(CalculateSettlementService::class)->findings($f['company'], $settlement))
        ->pluck('message')->implode(' ');

    // El preaviso en una renuncia lo DEBE el trabajador: rebajárselo sin su
    // autorización es una retención ilegal.
    expect($messages)->toContain('lo debe el TRABAJADOR');
});

it('paga cesantía por fallecimiento aunque no haya despido', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    $settlement = settlementFor($f, $employee, 'fallecimiento');

    expect(settlementLine($settlement, 'severance'))->not->toBeNull()
        ->and(settlementLine($settlement, 'notice'))->toBeNull();
});

// ── Las bases ───────────────────────────────────────────────────────────

it('promedia el devengado real y no el salario de la ficha', function () {
    $f = settlementFixture();
    // Ficha de ₡600.000, pero devengó ₡750.000 por horas extra.
    $employee = settlementEmployee($f, ['base_salary' => '600000.00']);
    settlementHistory($f, $employee, 6, '750000.00');

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');

    // ₡750.000 × 6 meses ÷ 180 días = ₡25.000 el día. Con la ficha habrían
    // sido ₡20.000: un 20% menos en cada día de cesantía y de preaviso.
    expect($settlement->average_daily_salary)->toBe('25000.00')
        ->and($settlement->average_monthly_salary)->toBe('750000.00')
        ->and($settlement->bases_from_history)->toBeTrue();
});

it('promedia sobre los meses que existen, no sobre seis', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f, ['hire_date' => '2026-06-01']);
    settlementHistory($f, $employee, 3, '600000.00');

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');

    // Dividir entre 6 cuando solo hay 3 meses de historial partiría el
    // promedio a la mitad y liquidaría con medio salario.
    expect($settlement->average_daily_salary)->toBe('20000.00')
        ->and($settlement->history_months_found)->toBe(3);
});

it('avisa cuando tiene que calcular con el salario de la ficha por falta de historial', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');

    expect($settlement->bases_from_history)->toBeFalse()
        ->and($settlement->average_daily_salary)->toBe('20000.00');

    $messages = collect(app(CalculateSettlementService::class)->findings($f['company'], $settlement))
        ->pluck('message')->implode(' ');

    expect($messages)->toContain('no con el promedio de lo devengado');
});

it('usa el divisor del semanal para el valor del día, no 30', function () {
    $f = settlementFixture();
    // El caso B del manual: ₡140.000 a la semana.
    $employee = settlementEmployee($f, [
        'salary_type' => 'semanal', 'base_salary' => '140000.00', 'weekly_salary_divisor' => 6,
    ]);

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');

    // Sin historial cae a la ficha, que ya divide entre 6. Antes de corregir
    // dailyRate esto daba ₡20.222,07.
    expect($settlement->average_daily_salary)->toBe('23333.33');
});

// ── Vacaciones ──────────────────────────────────────────────────────────

it('liquida las vacaciones con el saldo acumulado sin disfrutar', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    foreach ([['accrual', '14.0000'], ['enjoyment', '-4.0000']] as [$type, $days]) {
        VacationMovement::create([
            'company_id' => $f['company']->id, 'employee_id' => $employee->id,
            'type' => $type, 'movement_date' => '2026-08-31', 'days' => $days,
        ]);
    }

    $settlement = settlementFor($f, $employee, 'renuncia');

    $vacation = settlementLine($settlement, 'vacation');

    // 14 acumulados − 4 disfrutados = 10 por pagar, a ₡20.000 el día.
    expect($vacation->days)->toBe('10.0000')
        ->and($vacation->amount)->toBe('200000.00');
});

it('usa la misma base de seis meses para las vacaciones de un pago mensual', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '750000.00');
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'renuncia');

    // La práctica que indicó el usuario, aceptada por el Ministerio de
    // Trabajo: una sola base para toda la liquidación.
    expect($settlement->vacation_daily_salary)->toBe($settlement->average_daily_salary)
        ->and(settlementLine($settlement, 'vacation')->detail)->toContain('últimos 6 meses');
});

it('cambia a las últimas 50 semanas si la compañía escoge el criterio legal', function () {
    $f = settlementFixture();

    PayrollSetting::create([
        'company_id' => $f['company']->id,
        'vacation_average_basis' => 'legal_50_weeks',
    ]);

    $employee = settlementEmployee($f);
    // Once meses de historial: los últimos seis a ₡600.000 y los anteriores
    // a ₡900.000, así que las dos ventanas NO dan lo mismo.
    settlementHistory($f, $employee, 6, '600000.00', '2026-08-31');
    settlementHistory($f, $employee, 5, '900000.00', '2026-02-28');

    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'renuncia');

    expect($settlement->average_daily_salary)->toBe('20000.00')
        ->and($settlement->vacation_daily_salary)->toBeGreaterThan($settlement->average_daily_salary)
        ->and(settlementLine($settlement, 'vacation')->detail)->toContain('50 semanas');
});

it('avisa cuando la práctica deja las vacaciones por debajo del mínimo del art. 157', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    // Ganó más hace ocho meses que en el último semestre: es el único caso en
    // que la práctica perjudica al trabajador, y no puede quedar invisible.
    settlementHistory($f, $employee, 6, '600000.00', '2026-08-31');
    settlementHistory($f, $employee, 5, '900000.00', '2026-02-28');

    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'renuncia');

    $messages = collect(app(CalculateSettlementService::class)->findings($f['company'], $settlement))
        ->pluck('message')->implode(' ');

    expect($messages)->toContain('art. 157');
});

// ── Aguinaldo ───────────────────────────────────────────────────────────

it('divide el aguinaldo entre doce aunque se trabajen menos meses', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    // Nueve planillas del 1 de diciembre a la salida de agosto.
    settlementHistory($f, $employee, 9, '600000.00', '2026-08-31');

    $settlement = settlementFor($f, $employee, 'renuncia');

    // Diciembre a agosto son 9 meses × ₡600.000 = ₡5.400.000 ÷ 12.
    expect($settlement->christmas_bonus_base)->toBe('5400000.00')
        ->and(settlementLine($settlement, 'christmas_bonus')->amount)->toBe('450000.00');
});

// ── Cargas e impuesto ───────────────────────────────────────────────────

it('cobra cargas sociales solo sobre las vacaciones y los salarios pendientes', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');

    // Vacaciones: 10 días × ₡20.000 = ₡200.000. Cargas obreras 10,83%.
    expect($settlement->total_ccss)->toBe('21660.00');

    // Y la cesantía, el preaviso y el aguinaldo quedan exentos: es lo que más
    // se equivoca. Cobrarle el 10,83% a la cesantía le quita al trabajador
    // plata que la ley no permite rebajar.
    foreach (['severance', 'notice', 'christmas_bonus'] as $kind) {
        expect(settlementLine($settlement, $kind)->subject_to_ccss)->toBeFalse();
    }

    expect(settlementLine($settlement, 'vacation')->subject_to_ccss)->toBeTrue();
});

it('no le rebaja IVM a un pensionado', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f, ['is_pensioner' => true]);
    settlementHistory($f, $employee, 6, '600000.00');
    VacationMovement::create([
        'company_id' => $f['company']->id, 'employee_id' => $employee->id,
        'type' => 'accrual', 'movement_date' => '2026-08-31', 'days' => '10.0000',
    ]);

    $settlement = settlementFor($f, $employee, 'renuncia');

    // 6,50% en vez de 10,83%: ya está pensionado por el régimen de IVM.
    expect($settlement->total_ccss)->toBe('13000.00');
});

// ── Totales y ciclo de vida ─────────────────────────────────────────────

it('el neto es el bruto menos cargas, impuesto y deducciones', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad', '2026-08-31', [
        ['kind' => 'deduction', 'code' => 'PRESTAMO', 'name' => 'Saldo de préstamo', 'amount' => '150000.00'],
    ]);

    $expected = bcsub(
        $settlement->total_gross,
        bcadd(bcadd($settlement->total_ccss, $settlement->total_income_tax, 2),
            $settlement->total_other_deductions, 2),
        2
    );

    expect($settlement->total_net)->toBe($expected)
        ->and($settlement->total_other_deductions)->toBe('150000.00')
        // La deducción no infla el bruto: el bruto es lo que se devengó.
        ->and($settlement->total_gross)->toBe(
            $settlement->lines->where('kind', '!=', 'deduction')
                ->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 2), '0.00')
        );
});

it('acepta un renglón de días por tarifa y lo calcula', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    $settlement = settlementFor($f, $employee, 'renuncia', '2026-08-31', [
        ['kind' => 'pending_salary', 'code' => 'SALARIO', 'name' => 'Salarios pendientes', 'days' => '5'],
    ]);

    $line = settlementLine($settlement, 'pending_salary');

    // Sin tarifa explícita toma la base de la liquidación, y por ser salario
    // cotiza y tributa.
    expect($line->amount)->toBe('100000.00')
        ->and($line->subject_to_ccss)->toBeTrue()
        ->and($settlement->total_ccss)->toBe('10830.00');
});

it('recalcular no duplica renglones', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    $settlement = settlementFor($f, $employee, 'despido_con_responsabilidad');
    $first = $settlement->lines->count();

    $again = app(CalculateSettlementService::class)->calculate($f['company'], $settlement);

    expect($again->lines->count())->toBe($first);
});

it('no recalcula una liquidación que ya salió de borrador', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f);
    settlementHistory($f, $employee, 6, '600000.00');

    $settlement = settlementFor($f, $employee, 'renuncia');
    $settlement->update(['status' => 'approved']);

    expect(fn () => app(CalculateSettlementService::class)->calculate($f['company'], $settlement))
        ->toThrow(InvalidPayrollException::class);
});

it('rechaza una salida anterior al ingreso', function () {
    $f = settlementFixture();
    $employee = settlementEmployee($f, ['hire_date' => '2026-05-01']);

    $settlement = LaborSettlement::create([
        'company_id' => $f['company']->id,
        'employee_id' => $employee->id,
        'termination_date' => '2026-01-15',
        'reason' => 'renuncia',
        'status' => 'draft',
    ]);

    expect(fn () => app(CalculateSettlementService::class)->calculate($f['company'], $settlement))
        ->toThrow(InvalidPayrollException::class);
});
