<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Licensing\Models\License;
use App\Domains\Reporting\Models\SavedReport;
use App\Models\User;

function grantReportsAccess(User $user, Company $company, string $level): void
{
    $module = Module::firstOrCreate(['code' => 'reports'], ['name' => 'Reportería']);
    ModulePermission::updateOrCreate(
        ['company_id' => $company->id, 'module_id' => $module->id, 'subject_type' => 'user', 'subject_id' => $user->id],
        ['access_level' => $level]
    );
}

// --- Visibilidad -----------------------------------------------------------

it('un reporte privado no lo ve otro usuario de la misma compañía', function () {
    ['user' => $owner, 'company' => $company] = logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'Mi balance privado',
        'is_shared' => false,
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ])->assertSessionHasNoErrors();

    $other = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($other->id, ['is_default' => true]);
    grantAllModuleAccess($other, $company);
    $this->actingAs($other);

    $this->get(route('saved-reports.index'))
        ->assertInertia(fn ($page) => $page->has('savedReports', 0));
});

it('un reporte compartido lo ve otro usuario de la misma compañía', function () {
    ['user' => $owner, 'company' => $company] = logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'Balance compartido',
        'is_shared' => true,
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ]);

    $other = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($other->id, ['is_default' => true]);
    grantAllModuleAccess($other, $company);
    $this->actingAs($other);

    $this->get(route('saved-reports.index'))
        ->assertInertia(fn ($page) => $page
            ->has('savedReports', 1)
            ->where('savedReports.0.is_mine', false)
        );
});

it('ningún usuario de otra compañía ve un reporte guardado, aunque compartan licencia', function () {
    $license = License::factory()->create(['max_companies' => 5]);
    $companyA = Company::factory()->create(['license_id' => $license->id]);
    $companyB = Company::factory()->create(['license_id' => $license->id]);

    ['user' => $ownerA] = logInAsCompanyUser($companyA);
    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'Solo de A',
        'is_shared' => true,
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ]);

    ['user' => $userB] = logInAsCompanyUser($companyB);

    $this->get(route('saved-reports.index'))
        ->assertInertia(fn ($page) => $page->has('savedReports', 0));
});

// --- CRUD / ownership --------------------------------------------------------

it('el creador puede editar y eliminar su propio reporte guardado', function () {
    ['user' => $user] = logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'Original',
        'is_shared' => false,
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ]);
    $report = SavedReport::sole();

    $this->put(route('saved-reports.update', $report->id), [
        'name' => 'Renombrado',
        'is_shared' => true,
    ])->assertSessionHasNoErrors();

    expect($report->fresh()->name)->toBe('Renombrado')
        ->and($report->fresh()->is_shared)->toBeTrue();

    $this->delete(route('saved-reports.destroy', $report->id))->assertSessionHasNoErrors();
    expect(SavedReport::find($report->id))->toBeNull();
});

it('rechaza editar el reporte compartido de otro usuario sin permiso read_write en reports', function () {
    ['user' => $owner, 'company' => $company] = logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'De otro',
        'is_shared' => true,
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ]);
    $report = SavedReport::sole();

    $other = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($other->id, ['is_default' => true]);
    grantReportsAccess($other, $company, 'read');
    $this->actingAs($other);

    $this->put(route('saved-reports.update', $report->id), ['name' => 'Robado'])->assertForbidden();
    expect($report->fresh()->name)->toBe('De otro');
});

it('un usuario con read_write en reports puede eliminar el reporte compartido de otro', function () {
    ['user' => $owner, 'company' => $company] = logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'Compartido',
        'is_shared' => true,
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ]);
    $report = SavedReport::sole();

    $other = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($other->id, ['is_default' => true]);
    grantReportsAccess($other, $company, 'read_write');
    $this->actingAs($other);

    $this->delete(route('saved-reports.destroy', $report->id))->assertSessionHasNoErrors();
    expect(SavedReport::find($report->id))->toBeNull();
});

// --- Validación de store() ---------------------------------------------------

it('rechaza un report_code que no existe en el catálogo', function () {
    logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'reporte-inventado',
        'name' => 'x',
        'parameters' => [],
    ])->assertSessionHasErrors('report_code');
});

it('rechaza guardar un reporte sin un parámetro requerido', function () {
    logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'aging',
        'name' => 'Sin as_of',
        'parameters' => ['partner_type' => 'client'],
    ])->assertSessionHasErrors('parameters.as_of');
});

it('rechaza un valor de enum inválido', function () {
    logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'aging',
        'name' => 'Cartera',
        'parameters' => [
            'as_of' => ['type' => 'fixed', 'value' => '2026-01-31'],
            'partner_type' => 'vendor',
        ],
    ])->assertSessionHasErrors('parameters.partner_type');
});

// --- Invocación de punta a punta --------------------------------------------

it('invoca un reporte guardado y redirige al reporte real con los parámetros resueltos', function () {
    logInAsCompanyUser();

    $this->post(route('saved-reports.store'), [
        'report_code' => 'trial-balance',
        'name' => 'Balance de enero',
        'is_shared' => false,
        'parameters' => [
            'from' => ['type' => 'fixed', 'value' => '2026-01-01'],
            'to' => ['type' => 'fixed', 'value' => '2026-01-31'],
            'hide_zero' => false,
        ],
    ]);
    $report = SavedReport::sole();

    $response = $this->post(route('saved-reports.invoke', $report->id));

    $response->assertRedirect(route('reports.trial-balance.index', [
        'from' => '2026-01-01', 'to' => '2026-01-31', 'hide_zero' => false,
    ]));

    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/TrialBalance')
            ->where('from', '2026-01-01')
            ->where('to', '2026-01-31')
            ->where('hideZero', false)
        );

    expect($report->fresh()->last_run_at)->not->toBeNull();
});

// --- Gating por módulo --------------------------------------------------------

it('bloquea todo sin permiso en el módulo reports', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('saved-reports.index'))->assertForbidden();
    $this->post(route('saved-reports.store'), [])->assertForbidden();
});

it('read alcanza para listar e invocar, pero no para crear/editar/borrar', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    grantReportsAccess($user, $company, 'read');
    $this->actingAs($user);

    $this->get(route('saved-reports.index'))->assertOk();
    $this->post(route('saved-reports.store'), [
        'report_code' => 'balance-sheet',
        'name' => 'x',
        'parameters' => ['as_of' => ['type' => 'fixed', 'value' => '2026-01-31']],
    ])->assertForbidden();
});

it('un superusuario entra sin necesitar un permiso explícito en reports', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('saved-reports.index'))->assertOk();
});
