<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Models\OpeningBalance;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CodeRules;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\JobPosition;

/*
|--------------------------------------------------------------------------
| Los códigos de los datos maestros (CLAUDE.md secc. 36)
|--------------------------------------------------------------------------
|
| CONTAPP sugiere el que sigue; se decide al crear, y queda fijo desde que el
| registro tiene movimientos (CodeRules).
|
*/

function codesCompany(): Company
{
    ['company' => $company] = logInAsCompanyUser();
    app(CurrentCompany::class)->set($company->id);

    return $company;
}

function codesEmployee(Company $company, array $attributes = []): Employee
{
    static $n = 0;
    $n++;

    return Employee::create([
        'company_id' => $company->id,
        'code' => "CR-{$n}",
        'identification_type' => 'cedula',
        'identification_number' => '4'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
        'first_name' => 'Marta',
        'last_name1' => "Códigos{$n}",
        'hire_date' => '2024-01-01',
        'salary_type' => 'mensual',
        'base_salary' => '700000.00',
        'weekly_hours' => '48',
        'payment_method' => 'efectivo',
        'status' => 'active',
        ...$attributes,
    ]);
}

it('el código que sigue conserva el formato y salta los que ya existen', function () {
    $free = fn () => false;

    expect(CodeRules::next('PRV-0012', $free))->toBe('PRV-0013')
        ->and(CodeRules::next('PRV-0012', fn (string $code) => in_array($code, ['PRV-0013', 'PRV-0014'], true)))->toBe('PRV-0015')
        ->and(CodeRules::next('1-01-01-099', $free))->toBe('1-01-01-100')
        ->and(CodeRules::next('E9', $free))->toBe('E10')
        // Sin número al final no hay serie que seguir.
        ->and(CodeRules::next('MAYORISTA', $free))->toBeNull()
        ->and(CodeRules::next(null, $free))->toBeNull();
});

it('sigue al último creado en la compañía; sin ninguno, el primero de la serie', function () {
    $company = codesCompany();

    expect(CodeRules::following(BpCategory::query()))->toBeNull()
        ->and(CodeRules::following(BpCategory::query(), 'CAT-001'))->toBe('CAT-001');

    BpCategory::factory()->create(['company_id' => $company->id, 'code' => 'CAT-007']);
    // La de otra compañía no cuenta.
    BpCategory::factory()->create(['company_id' => Company::factory()->create()->id, 'code' => 'CAT-050']);

    expect(CodeRules::following(BpCategory::query()))->toBe('CAT-008');
});

it('cuenta contable: el código se corrige hasta que tiene movimientos; después queda fijo', function () {
    $company = codesCompany();
    $account = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-001', 'description_es' => 'Caja']);
    $form = fn (string $code, string $description = 'Caja') => [
        'code' => $code, 'description_es' => $description, 'account_type' => 'asset',
        'currency_mode' => 'local', 'tax_classification' => 'none', 'accepts_posting' => true, 'is_active' => true,
    ];

    $this->put(route('chart-of-accounts.update', $account->id), $form('1-01-01-002'))->assertSessionHasNoErrors();
    expect($account->fresh()->code)->toBe('1-01-01-002');

    // Un saldo inicial alcanza (lo mismo un asiento, aunque sea borrador).
    $year = FiscalYear::factory()->create(['company_id' => $company->id]);
    OpeningBalance::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'account_id' => $account->id, 'debit_local' => 1000]);

    $this->put(route('chart-of-accounts.update', $account->id), $form('1-01-01-009'))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: la cuenta ya tiene asientos o saldo inicial.']);
    expect($account->fresh()->code)->toBe('1-01-01-002');

    // Lo demás se sigue editando.
    $this->put(route('chart-of-accounts.update', $account->id), $form('1-01-01-002', 'Caja general'))->assertSessionHasNoErrors();
    expect($account->fresh()->description_es)->toBe('Caja general');

    $this->get(route('chart-of-accounts.index'))
        ->assertInertia(fn ($page) => $page->where('accounts.0.code', '1-01-01-002')->where('accounts.0.code_locked', true));
});

it('cuenta contable: sugiere la que sigue a la última de esa clase, en la misma cuenta madre', function () {
    $company = codesCompany();
    $parent = ChartOfAccount::factory()->nonPosting()->create(['company_id' => $company->id, 'code' => '1-01-01']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'parent_id' => $parent->id, 'code' => '1-01-01-001']);
    ChartOfAccount::factory()->create(['company_id' => $company->id, 'parent_id' => $parent->id, 'code' => '1-01-01-002']);

    $this->get(route('chart-of-accounts.index'))->assertInertia(fn ($page) => $page
        ->where('nextCodes.asset', '1-01-01-003')
        // Sin cuentas de esa clase, nada que seguir.
        ->where('nextCodes.liability', null));
});

it('socio de negocio: CONTAPP propone el código según el tipo, y desde que se crea queda fijo', function () {
    $company = codesCompany();

    $this->get(route('business-partners.create'))->assertInertia(fn ($page) => $page
        ->where('nextCodes.client', 'CLI-0001')
        ->where('nextCodes.supplier', 'PRV-0001')
        ->where('nextCodes.both', 'SOC-0001'));

    $account = ChartOfAccount::factory()->create(['company_id' => $company->id]);
    $partner = BusinessPartner::factory()->create([
        'company_id' => $company->id, 'code' => 'PRV-0007', 'type' => 'supplier',
        'gl_account_id' => $account->id, 'currency_id' => $company->local_currency_id,
    ]);

    $this->get(route('business-partners.create'))->assertInertia(fn ($page) => $page
        ->where('nextCodes.supplier', 'PRV-0008')
        ->where('nextCodes.client', 'CLI-0001'));

    $form = fn (string $code) => [
        'code' => $code, 'name' => 'Distribuidora del Valle', 'type' => 'supplier',
        'gl_account_id' => $account->id, 'currency_id' => $company->local_currency_id,
    ];

    $this->put(route('business-partners.update', $partner->id), $form('PRV-0099'))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: un socio conserva su código desde que se crea.']);

    $this->put(route('business-partners.update', $partner->id), $form('PRV-0007'))->assertSessionHasNoErrors();
    expect($partner->fresh()->only(['code', 'name']))->toBe(['code' => 'PRV-0007', 'name' => 'Distribuidora del Valle']);
});

it('categoría, departamento y puesto: el código se corrige hasta que se usan', function () {
    $company = codesCompany();

    $category = BpCategory::factory()->create(['company_id' => $company->id, 'code' => 'CAT-001']);
    $this->put(route('bp-categories.update', $category->id), ['code' => 'CAT-010', 'name' => 'Mayoristas'])->assertSessionHasNoErrors();

    BusinessPartner::factory()->create(['company_id' => $company->id, 'category_id' => $category->id]);
    $this->put(route('bp-categories.update', $category->id), ['code' => 'CAT-011', 'name' => 'Mayoristas'])
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: ya hay socios en esta categoría.']);
    $this->put(route('bp-categories.update', $category->id), ['code' => 'CAT-010', 'name' => 'Clientes mayoristas'])->assertSessionHasNoErrors();

    $this->get(route('bp-categories.index'))->assertInertia(fn ($page) => $page
        ->where('categories.0.code_locked', true)
        ->where('nextCode', 'CAT-011'));

    $department = Department::create(['company_id' => $company->id, 'code' => 'D-01', 'name' => 'Ventas', 'status' => 'active']);
    $position = JobPosition::create(['company_id' => $company->id, 'code' => 'P-01', 'name' => 'Vendedor', 'status' => 'active']);
    $departmentForm = fn (string $code) => ['code' => $code, 'name' => 'Ventas', 'status' => 'active'];
    $positionForm = fn (string $code) => ['code' => $code, 'name' => 'Vendedor', 'status' => 'active'];

    $this->put(route('departments.update', $department->id), $departmentForm('D-02'))->assertSessionHasNoErrors();
    $this->put(route('job-positions.update', $position->id), $positionForm('P-02'))->assertSessionHasNoErrors();

    // Con un puesto adentro, el departamento ya no cambia de código.
    $position->update(['department_id' => $department->id]);
    $this->put(route('departments.update', $department->id), $departmentForm('D-03'))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: el departamento ya tiene trabajadores o puestos.']);

    // Con gente adentro, el puesto tampoco.
    codesEmployee($company, ['job_position_id' => $position->id]);
    $this->put(route('job-positions.update', $position->id), $positionForm('P-03'))
        ->assertSessionHasErrors(['code' => 'El código no se puede cambiar: el puesto ya tiene trabajadores.']);

    expect([$department->fresh()->code, $position->fresh()->code])->toBe(['D-02', 'P-02']);

    $this->get(route('job-structure.index'))->assertInertia(fn ($page) => $page
        ->where('departments.0.code_locked', true)
        ->where('positions.0.code_locked', true)
        ->where('nextDepartmentCode', 'D-03')
        ->where('nextPositionCode', 'P-03'));
});

it('en los demás catálogos sugiere el código que sigue al último', function () {
    $company = codesCompany();

    $this->get(route('items.index'))->assertInertia(fn ($page) => $page->where('nextCode', null));

    Item::factory()->create(['company_id' => $company->id, 'code' => 'ART-0009']);

    $this->get(route('items.index'))->assertInertia(fn ($page) => $page->where('nextCode', 'ART-0010'));
});
