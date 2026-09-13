<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Models\User;

it('bloquea el reporte de IVA a un usuario sin permiso en el módulo tax', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('tax-report.index'))->assertForbidden();
});

it('el catálogo de indicadores de impuesto queda fuera del gate: sigue accesible sin permiso de tax', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('tax-rates.index'))->assertOk();
});

it('un usuario con read en el módulo tax entra al reporte de IVA', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);

    $taxModule = Module::firstOrCreate(['code' => 'tax'], ['name' => 'Impuestos (IVA)']);
    ModulePermission::create([
        'company_id' => $company->id, 'module_id' => $taxModule->id,
        'subject_type' => 'user', 'subject_id' => $user->id, 'access_level' => 'read',
    ]);

    $this->actingAs($user);

    $this->get(route('tax-report.index'))->assertOk();
});

it('un superusuario entra al reporte de IVA sin necesitar un permiso explícito', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['is_super_admin' => true, 'default_company_id' => $company->id]);
    $company->users()->attach($user->id, ['is_default' => true]);
    $this->actingAs($user);

    $this->get(route('tax-report.index'))->assertOk();
});
