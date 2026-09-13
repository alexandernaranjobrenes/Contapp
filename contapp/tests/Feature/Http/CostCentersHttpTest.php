<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

it('solo lista los centros de costo de la compañía activa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $ccA = CostCenter::factory()->create(['company_id' => $companyA->id, 'code' => 'CC-01']);
    CostCenter::factory()->create(['company_id' => $companyB->id, 'code' => 'CC-01']);

    logInAsCompanyUser($companyA);

    $this->get(route('cost-centers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('CostCenters/Index')
            ->has('costCenters', 1)
            ->where('costCenters.0.code', $ccA->code)
        );
});

it('crea un centro de costo con código y nombre', function () {
    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('cost-centers.store'), [
        'code' => 'ADM',
        'name' => 'Administración',
        'start_date' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    $cc = CostCenter::where('company_id', $company->id)->sole();

    expect($cc->code)->toBe('ADM')
        ->and($cc->name)->toBe('Administración');
});

it('rechaza un código duplicado dentro de la misma compañía', function () {
    ['company' => $company] = logInAsCompanyUser();
    CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'ADM']);

    $this->post(route('cost-centers.store'), [
        'code' => 'ADM',
        'name' => 'Repetido',
        'start_date' => '2026-01-01',
    ])->assertSessionHasErrors('code');

    expect(CostCenter::where('company_id', $company->id)->count())->toBe(1);
});

it('permite el mismo código en compañías distintas', function () {
    $companyB = Company::factory()->create();
    CostCenter::factory()->create(['company_id' => $companyB->id, 'code' => 'ADM']);

    ['company' => $company] = logInAsCompanyUser();

    $this->post(route('cost-centers.store'), [
        'code' => 'ADM',
        'name' => 'Administración',
        'start_date' => '2026-01-01',
    ])->assertSessionHasNoErrors();

    expect(CostCenter::where('company_id', $company->id)->where('code', 'ADM')->exists())->toBeTrue();
});

it('edita nombre, vigencia y estado de un centro de costo sin tocar su código', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'ADM', 'name' => 'Viejo nombre']);

    $this->put(route('cost-centers.update', $cc->id), [
        'name' => 'Administración general',
        'start_date' => '2026-02-01',
        'end_date' => '2026-12-31',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    $cc->refresh();
    expect($cc->name)->toBe('Administración general')
        ->and($cc->code)->toBe('ADM')
        ->and($cc->end_date->format('Y-m-d'))->toBe('2026-12-31')
        ->and($cc->is_active)->toBeFalse();
});

it('rechaza actualizar un centro de costo de otra compañía', function () {
    $companyB = Company::factory()->create();
    $ccB = CostCenter::factory()->create(['company_id' => $companyB->id, 'code' => 'ADM']);

    logInAsCompanyUser();

    $this->put(route('cost-centers.update', $ccB->id), [
        'name' => 'Intento ajeno',
        'start_date' => '2026-01-01',
    ])->assertNotFound();
});

it('elimina un centro de costo sin movimientos ni normas de reparto', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'ADM']);

    $this->delete(route('cost-centers.destroy', $cc->id))->assertSessionHasNoErrors();

    expect(CostCenter::find($cc->id))->toBeNull();
});

it('rechaza eliminar un centro de costo referenciado en una norma de reparto', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'ADM']);
    CostAllocationRule::factory()->withEvenSplit($cc)->create(['company_id' => $company->id, 'code' => 'NORMA1']);

    $this->delete(route('cost-centers.destroy', $cc->id))->assertSessionHasErrors('cost_center');

    expect(CostCenter::find($cc->id))->not->toBeNull();
});

it('rechaza eliminar un centro de costo que ya tiene movimientos contabilizados', function () {
    ['company' => $company] = logInAsCompanyUser();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => now()->format('Y-m-d'),
        'rate' => '520.000000',
    ]);

    $gasto = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '6-01-01-01-001']);
    $equity = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '3-01-01-01-001']);
    $add = DocumentType::factory()->create(['company_id' => $company->id, 'code' => 'ADD']);
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'ADM']);
    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'NORMA1']);
    $rule->lines()->create(['cost_center_id' => $cc->id, 'percentage' => '100.00', 'position' => 1]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => (int) now()->format('Y')]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_number' => (int) now()->format('n'),
        'start_date' => now()->startOfMonth()->format('Y-m-d'),
        'end_date' => now()->endOfMonth()->format('Y-m-d'),
        'status' => 'open',
    ]);

    app(PostJournalService::class)->post($company, $add, now(), now(), [
        new JournalLineInput($gasto->id, $company->local_currency_id, debit: 100, credit: 0, costAllocationRuleId: $rule->id),
        new JournalLineInput($equity->id, $company->local_currency_id, debit: 0, credit: 100),
    ]);

    $this->delete(route('cost-centers.destroy', $cc->id))->assertSessionHasErrors('cost_center');

    expect(CostCenter::find($cc->id))->not->toBeNull();
});
