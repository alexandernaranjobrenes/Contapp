<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollInput;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\CostaRicaPayrollDefaults;
use App\Domains\Payroll\Services\PayrollReadinessChecker;
use App\Models\User;
use App\Notifications\EmployeeBankChangeNotification;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Códigos y consecutivos de planilla, y el cambio de cuenta bancaria
|--------------------------------------------------------------------------
|
| CLAUDE.md secc. 36: el código del empleado queda fijo desde su primera
| planilla; los conceptos, desde que se usan; las cargas sociales, siempre;
| los períodos van en orden. Y la cuenta bancaria que cambia alguien que no
| es el Superusuario queda pendiente hasta que él la apruebe.
|
*/

beforeEach(fn () => Notification::fake());

const BANK_OLD = 'CR05015202001026284066';
const BANK_NEW = 'CR21015201001024247624';

/** Una compañía con licencia: su Superusuario y Carla, que tiene la pantalla de empleados. */
function bankFixture(): array
{
    ['user' => $super, 'company' => $company] = logInAsCompanyUser(null, ['is_super_admin' => true]);
    $company->update(['license_id' => License::factory()->create(['superuser_id' => $super->id])->id]);
    app(CurrentCompany::class)->set($company->id);

    $clerk = User::factory()->create(['default_company_id' => $company->id, 'status' => 'active', 'name' => 'Carla Planillas']);
    $company->users()->attach($clerk->id, ['is_default' => true]);
    app(PermissionGrantService::class)->writeScreenLevels($company->id, $clerk, ['payroll.employees' => 'read_write']);

    $employee = payrollCodesEmployee($company, [
        'payment_method' => 'transferencia', 'bank_name' => 'BNCR', 'bank_account' => BANK_OLD,
    ]);

    return compact('company', 'super', 'clerk', 'employee');
}

function payrollCodesEmployee(Company $company, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create([
        'company_id' => $company->id,
        'code' => "EMP-000{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '5'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
        'first_name' => 'Laura',
        'last_name1' => "Planilla{$n}",
        'hire_date' => '2024-01-01',
        'salary_type' => 'mensual',
        'base_salary' => '700000.00',
        'weekly_hours' => '48',
        'payment_method' => 'efectivo',
        'status' => 'active',
        ...$attributes,
    ]);
}

/** El formulario de edición del empleado, como lo manda la pantalla. */
function bankForm(Employee $employee, array $override = []): array
{
    return [
        'code' => $employee->code,
        'identification_type' => 'cedula',
        'identification_number' => $employee->identification_number,
        'first_name' => $employee->first_name,
        'last_name1' => $employee->last_name1,
        'hire_date' => '2024-01-01',
        'contract_type' => 'indefinido',
        'journey_type' => 'diurna',
        'weekly_hours' => 48,
        'salary_type' => 'mensual',
        'base_salary' => 700000,
        'payment_method' => $employee->payment_method,
        'bank_name' => $employee->bank_name,
        'bank_account' => $employee->bank_account,
        'status' => 'active',
        ...$override,
    ];
}

function payrollCodesPeriod(Company $company, int $number = 7): PayrollPeriod
{
    $month = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

    return PayrollPeriod::create([
        'company_id' => $company->id,
        'year' => 2026, 'frequency' => 'mensual', 'number' => $number, 'name' => "Mes {$number} 2026",
        'start_date' => "2026-{$month}-01", 'end_date' => "2026-{$month}-28", 'payment_date' => "2026-{$month}-28",
        'status' => 'open',
    ]);
}

it('empleado: se propone EMP-0001 y después el que sigue', function () {
    ['company' => $company] = logInAsCompanyUser();
    app(CurrentCompany::class)->set($company->id);

    $this->get(route('employees.index'))->assertInertia(fn ($page) => $page->where('nextCode', 'EMP-0001'));

    payrollCodesEmployee($company, ['code' => 'EMP-0004']);

    $this->get(route('employees.index'))->assertInertia(fn ($page) => $page->where('nextCode', 'EMP-0005'));
});

it('empleado: el código se corrige hasta su primera planilla; después queda fijo', function () {
    $f = bankFixture();
    $employee = $f['employee'];

    $this->put(route('employees.update', $employee->id), bankForm($employee, ['code' => 'EMP-0100']))->assertSessionHasNoErrors();
    expect($employee->fresh()->code)->toBe('EMP-0100');

    PayrollEntry::create([
        'payroll_period_id' => payrollCodesPeriod($f['company'])->id,
        'employee_id' => $employee->id, 'days_worked' => 30, 'base_salary' => '700000.00',
    ]);

    $this->put(route('employees.update', $employee->id), bankForm($employee->fresh(), ['code' => 'EMP-0200']))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: el empleado ya tiene planillas calculadas.']);
    expect($employee->fresh()->code)->toBe('EMP-0100');

    $this->get(route('employees.index'))->assertInertia(fn ($page) => $page->where('employees.0.code_locked', true));
});

it('la cuenta bancaria que cambia otra persona queda pendiente, y se le avisa al Superusuario', function () {
    $f = bankFixture();
    $employee = $f['employee'];

    $this->actingAs($f['clerk'])
        ->put(route('employees.update', $employee->id), bankForm($employee, ['bank_name' => 'BCR', 'bank_account' => BANK_NEW, 'notes' => 'Lo pidió por correo']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'queda pendiente'));

    $employee->refresh();
    // Se le sigue pagando a la cuenta confirmada; lo demás sí se guardó.
    expect($employee->only(['bank_name', 'bank_account', 'pending_bank_name', 'pending_bank_account', 'bank_change_requested_by', 'notes']))
        ->toBe([
            'bank_name' => 'BNCR', 'bank_account' => BANK_OLD,
            'pending_bank_name' => 'BCR', 'pending_bank_account' => BANK_NEW,
            'bank_change_requested_by' => $f['clerk']->id, 'notes' => 'Lo pidió por correo',
        ]);
    Notification::assertSentTo($f['super'], EmployeeBankChangeNotification::class);
    Notification::assertNotSentTo($f['clerk'], EmployeeBankChangeNotification::class);

    // Guardar otra vez lo mismo no vuelve a avisar.
    $this->actingAs($f['clerk'])->put(route('employees.update', $employee->id), bankForm($employee, ['bank_name' => 'BCR', 'bank_account' => BANK_NEW]));
    Notification::assertSentToTimes($f['super'], EmployeeBankChangeNotification::class, 1);

    // La verificación previa de la planilla lo advierte.
    $findings = app(PayrollReadinessChecker::class)->check($f['company'], payrollCodesPeriod($f['company']))['findings'];
    expect(collect($findings)->pluck('title')->all())->toContain("{$employee->code} — {$employee->fullName()} tiene un cambio de cuenta bancaria pendiente");

    // Aprobarlo es solo del Superusuario.
    $this->actingAs($f['clerk'])->post(route('employees.bank-change.approve', $employee->id))->assertForbidden();
    $this->actingAs($f['super'])->post(route('employees.bank-change.approve', $employee->id))->assertSessionHas('success');

    expect($employee->fresh()->only(['bank_name', 'bank_account', 'pending_bank_account', 'bank_change_requested_by']))
        ->toBe(['bank_name' => 'BCR', 'bank_account' => BANK_NEW, 'pending_bank_account' => null, 'bank_change_requested_by' => null]);
});

it('el Superusuario rechaza un cambio pendiente, y él cambia la cuenta directo', function () {
    $f = bankFixture();
    $employee = $f['employee'];

    // Sin tocar la cuenta, no hay nada pendiente ni aviso.
    $this->actingAs($f['clerk'])->put(route('employees.update', $employee->id), bankForm($employee, ['first_name' => 'Lorena']))->assertSessionHasNoErrors();
    expect($employee->fresh()->hasPendingBankChange())->toBeFalse();
    Notification::assertNothingSent();

    $this->actingAs($f['clerk'])->put(route('employees.update', $employee->id), bankForm($employee->fresh(), ['bank_account' => BANK_NEW]));
    $this->actingAs($f['clerk'])->post(route('employees.bank-change.reject', $employee->id))->assertForbidden();
    $this->actingAs($f['super'])->post(route('employees.bank-change.reject', $employee->id))->assertSessionHas('success');

    expect($employee->fresh()->only(['bank_account', 'pending_bank_account']))->toBe(['bank_account' => BANK_OLD, 'pending_bank_account' => null]);

    Notification::fake();
    $this->actingAs($f['super'])->put(route('employees.update', $employee->id), bankForm($employee->fresh(), ['bank_account' => BANK_NEW]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => ! str_contains($message, 'pendiente'));

    expect($employee->fresh()->bank_account)->toBe(BANK_NEW);
    Notification::assertNothingSent();
});

it('conceptos: los que trae Pivox y los ya usados no cambian de código', function () {
    ['company' => $company] = logInAsCompanyUser();
    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $form = fn (PayrollConcept $concept, string $code) => [
        'code' => $code, 'name' => $concept->name, 'type' => $concept->type, 'sign' => $concept->sign,
        'calculation' => $concept->calculation, 'status' => 'active',
    ];

    $default = PayrollConcept::where('code', CostaRicaPayrollDefaults::CONCEPTS[0]['code'])->sole();
    $this->put(route('payroll-settings.concepts.update', $default->id), $form($default, 'OTRO'))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: es un concepto que trae Pivox.']);

    $own = PayrollConcept::create([
        'company_id' => $company->id, 'code' => 'BONO-X', 'name' => 'Bono de ventas', 'type' => 'earning',
        'sign' => 1, 'calculation' => 'amount', 'status' => 'active',
    ]);
    $this->put(route('payroll-settings.concepts.update', $own->id), $form($own, 'BONO-Y'))->assertSessionHasNoErrors();
    expect($own->fresh()->code)->toBe('BONO-Y');

    PayrollInput::create([
        'company_id' => $company->id, 'payroll_period_id' => payrollCodesPeriod($company)->id,
        'employee_id' => payrollCodesEmployee($company)->id, 'payroll_concept_id' => $own->id, 'amount' => '1000.00',
    ]);
    $this->put(route('payroll-settings.concepts.update', $own->id), $form($own->fresh(), 'BONO-Z'))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: ya se usó en planillas o en rubros.']);
    expect($own->fresh()->code)->toBe('BONO-Y');

    $this->get(route('payroll-settings.index'))->assertInertia(fn ($page) => $page
        ->where('concepts', fn ($concepts) => collect($concepts)->firstWhere('code', 'BONO-Y')['code_locked'] === true
            && collect($concepts)->firstWhere('code', $default->code)['code_locked'] === true));
});

it('cargas sociales: el código une sus vigencias y no se cambia; lo demás sí', function () {
    ['company' => $company] = logInAsCompanyUser();
    app(CurrentCompany::class)->set($company->id);
    app(CostaRicaPayrollDefaults::class)->load($company, '2026-01-01');

    $contribution = PayrollContribution::where('company_id', $company->id)->orderBy('id')->first();
    $form = fn (array $override) => [
        'code' => $contribution->code, 'name' => $contribution->name, 'payer' => $contribution->payer,
        'institution' => $contribution->institution, 'percentage' => $contribution->percentage, 'base' => $contribution->base,
        'valid_from' => $contribution->valid_from->format('Y-m-d'), 'status' => 'active',
        ...$override,
    ];

    $this->put(route('payroll-settings.contributions.update', $contribution->id), $form(['code' => 'OTRA']))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: une las vigencias de la misma carga; para cambiar la tasa, agregá una vigencia nueva.']);

    $this->put(route('payroll-settings.contributions.update', $contribution->id), $form(['name' => 'Nombre corregido']))->assertSessionHasNoErrors();
    expect($contribution->fresh()->only(['code', 'name']))->toBe(['code' => $contribution->code, 'name' => 'Nombre corregido']);
});

it('períodos: van en orden y sin saltos; el primero del año puede ser cualquiera', function () {
    ['company' => $company] = logInAsCompanyUser();
    $form = function (int $number) {
        $month = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

        return [
            'year' => 2026, 'frequency' => 'mensual', 'number' => $number, 'name' => "Mes {$number}",
            'start_date' => "2026-{$month}-01", 'end_date' => "2026-{$month}-28", 'payment_date' => "2026-{$month}-28",
        ];
    };

    // Una compañía que empieza a usar Pivox a mitad de año.
    $this->post(route('payroll-periods.store'), $form(7))->assertSessionHasNoErrors();

    $this->post(route('payroll-periods.store'), $form(9))
        ->assertSessionHasErrors(['number' => 'El período que sigue es el 8 de 2026: no se puede saltar al 9.']);

    $this->post(route('payroll-periods.store'), $form(8))->assertSessionHasNoErrors();

    expect(PayrollPeriod::where('company_id', $company->id)->orderBy('number')->pluck('number')->all())->toBe([7, 8]);
});
