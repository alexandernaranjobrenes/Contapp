<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;

it('no expone filas de ninguna compañía cuando no hay compañía activa', function () {
    Company::factory()->create();
    $company = Company::factory()->create();
    ChartOfAccount::factory()->create(['company_id' => $company->id]);

    expect(ChartOfAccount::count())->toBe(0);
});

it('aísla el catálogo de cuentas entre compañías', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $accountA = ChartOfAccount::factory()->create(['company_id' => $companyA->id]);
    $accountB = ChartOfAccount::factory()->create(['company_id' => $companyB->id]);

    $currentCompany = app(CurrentCompany::class);

    $currentCompany->set($companyA);
    expect(ChartOfAccount::pluck('id')->all())->toBe([$accountA->id]);

    $currentCompany->set($companyB);
    expect(ChartOfAccount::pluck('id')->all())->toBe([$accountB->id]);
});

it('permite cruzar compañías explícitamente vía withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    ChartOfAccount::factory()->create(['company_id' => $companyA->id]);
    ChartOfAccount::factory()->create(['company_id' => $companyB->id]);

    expect(ChartOfAccount::withoutGlobalScope(CompanyScope::class)->count())->toBe(2);
});
