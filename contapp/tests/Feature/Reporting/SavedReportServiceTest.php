<?php

use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Models\SavedReport;
use App\Domains\Reporting\Services\SavedReportService;
use App\Models\User;
use Illuminate\Support\Facades\Date;

it('resuelve una fecha relativa al momento de invocar, no al de guardar', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $user = User::factory()->create(['default_company_id' => $company->id]);

    Date::setTestNow('2026-01-15');
    $service = app(SavedReportService::class);
    $report = $service->create($user, 'trial-balance', 'Cierre del mes', [
        'from' => ['type' => 'relative', 'value' => 'start_of_month'],
        'hide_zero' => true,
    ], false);

    Date::setTestNow('2026-03-10');
    $resolved = $service->resolveParametersForInvocation($report);

    expect($resolved['from'])->toBe('2026-03-01')
        ->and($resolved['hide_zero'])->toBeTrue();

    Date::setTestNow();
});

it('marca last_run_at al invocar', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $user = User::factory()->create(['default_company_id' => $company->id]);

    $service = app(SavedReportService::class);
    $report = $service->create($user, 'balance-sheet', 'Cierre', [
        'as_of' => ['type' => 'fixed', 'value' => '2026-01-31'],
    ], false);

    expect($report->last_run_at)->toBeNull();

    $service->resolveParametersForInvocation($report);

    expect($report->fresh()->last_run_at)->not->toBeNull();
});

it('isStale es false para un payload bien formado', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $user = User::factory()->create(['default_company_id' => $company->id]);

    $service = app(SavedReportService::class);
    $report = $service->create($user, 'aging', 'Cartera vencida', [
        'as_of' => ['type' => 'fixed', 'value' => '2026-01-31'],
        'partner_type' => 'client',
    ], false);

    expect($service->isStale($report))->toBeFalse();
});

it('isStale es true cuando un valor de enum guardado ya no es válido', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $user = User::factory()->create(['default_company_id' => $company->id]);

    $service = app(SavedReportService::class);
    $report = $service->create($user, 'aging', 'Cartera vieja', [
        'as_of' => ['type' => 'fixed', 'value' => '2026-01-31'],
        'partner_type' => 'vendor', // ya no es una opción válida (both|client|supplier)
    ], false);

    expect($service->isStale($report))->toBeTrue();
});

it('isStale es true cuando el código de reporte ya no existe en el catálogo', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company->id);
    $user = User::factory()->create(['default_company_id' => $company->id]);

    $report = new SavedReport([
        'report_code' => 'reporte-descontinuado',
        'name' => 'Viejo',
        'parameters' => ['x' => 'y'],
        'is_shared' => false,
    ]);
    $report->user_id = $user->id;
    $report->save();

    expect(app(SavedReportService::class)->isStale($report))->toBeTrue();
});
