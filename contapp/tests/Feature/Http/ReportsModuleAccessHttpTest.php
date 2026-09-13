<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Models\User;

function reportRouteNames(): array
{
    return [
        'reports.trial-balance.index',
        'reports.income-statement.index',
        'reports.balance-sheet.index',
        'reports.aging.index',
        'reports.cash-flow-projection.index',
        'reports.multi-company-comparison.index',
    ];
}

it('bloquea las 6 pantallas de reportería a un usuario sin permiso en el módulo reports', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    foreach (reportRouteNames() as $name) {
        $this->get(route($name))->assertForbidden();
    }
});

it('el permiso de "reports" no da acceso a tax-report: es del módulo "tax" (ver TaxModuleAccessHttpTest)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $reportsModule = Module::firstOrCreate(['code' => 'reports'], ['name' => 'Reportería']);
    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => $reportsModule->id,
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => 'read_write',
    ]);

    $this->actingAs($user);

    $this->get(route('tax-report.index'))->assertForbidden();
});

it('un usuario con read en el módulo reports entra a las 6 pantallas', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $reportsModule = Module::firstOrCreate(['code' => 'reports'], ['name' => 'Reportería']);
    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => $reportsModule->id,
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => 'read',
    ]);

    $this->actingAs($user);

    foreach (reportRouteNames() as $name) {
        $this->get(route($name))->assertOk();
    }
});

it('un superusuario entra a las pantallas de reportería sin necesitar un permiso explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('reports.trial-balance.index'))->assertOk();
});
