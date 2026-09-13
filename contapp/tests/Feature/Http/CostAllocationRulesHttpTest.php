<?php

use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Core\Models\Company;

it('solo lista las normas de reparto de la compañía activa del usuario autenticado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $ruleA = CostAllocationRule::factory()->create(['company_id' => $companyA->id, 'code' => 'NORMA1']);
    CostAllocationRule::factory()->create(['company_id' => $companyB->id, 'code' => 'NORMA1']);

    logInAsCompanyUser($companyA);

    $this->get(route('cost-allocation-rules.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('CostAllocationRules/Index')
            ->has('rules', 1)
            ->where('rules.0.code', $ruleA->code)
        );
});

it('crea una norma de reparto con líneas que suman exactamente 100%', function () {
    ['company' => $company] = logInAsCompanyUser();
    $ccA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'A']);
    $ccB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'B']);

    $this->post(route('cost-allocation-rules.store'), [
        'code' => 'TRANSP',
        'name' => 'Transportes',
        'valid_from' => '2026-01-01',
        'lines' => [
            ['cost_center_id' => $ccA->id, 'percentage' => 60],
            ['cost_center_id' => $ccB->id, 'percentage' => 40],
        ],
    ])->assertSessionHasNoErrors();

    $rule = CostAllocationRule::where('company_id', $company->id)->sole();
    expect($rule->lines)->toHaveCount(2)
        ->and($rule->lines->sum('percentage'))->toEqual('100.00');
});

it('rechaza una norma cuyas líneas no suman 100%', function () {
    ['company' => $company] = logInAsCompanyUser();
    $ccA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'A']);
    $ccB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'B']);

    $this->post(route('cost-allocation-rules.store'), [
        'code' => 'TRANSP',
        'name' => 'Transportes',
        'valid_from' => '2026-01-01',
        'lines' => [
            ['cost_center_id' => $ccA->id, 'percentage' => 60],
            ['cost_center_id' => $ccB->id, 'percentage' => 30],
        ],
    ])->assertSessionHasErrors('lines');

    expect(CostAllocationRule::where('company_id', $company->id)->count())->toBe(0);
});

it('rechaza un código de norma duplicado dentro de la misma compañía', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'A']);
    CostAllocationRule::factory()->withEvenSplit($cc)->create(['company_id' => $company->id, 'code' => 'TRANSP']);

    $this->post(route('cost-allocation-rules.store'), [
        'code' => 'TRANSP',
        'name' => 'Repetida',
        'valid_from' => '2026-01-01',
        'lines' => [['cost_center_id' => $cc->id, 'percentage' => 100]],
    ])->assertSessionHasErrors('code');
});

it('rechaza dos líneas de la misma norma con el mismo centro de costo', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'A']);

    $this->post(route('cost-allocation-rules.store'), [
        'code' => 'TRANSP',
        'name' => 'Transportes',
        'valid_from' => '2026-01-01',
        'lines' => [
            ['cost_center_id' => $cc->id, 'percentage' => 50],
            ['cost_center_id' => $cc->id, 'percentage' => 50],
        ],
    ])->assertSessionHasErrors('lines.0.cost_center_id');
});

it('actualiza una norma reemplazando sus líneas', function () {
    ['company' => $company] = logInAsCompanyUser();
    $ccA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'A']);
    $ccB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'B']);
    $rule = CostAllocationRule::factory()->withEvenSplit($ccA)->create(['company_id' => $company->id, 'code' => 'TRANSP']);

    $this->put(route('cost-allocation-rules.update', $rule->id), [
        'code' => 'TRANSP',
        'name' => 'Transportes renombrada',
        'valid_from' => '2026-01-01',
        'lines' => [
            ['cost_center_id' => $ccA->id, 'percentage' => 25],
            ['cost_center_id' => $ccB->id, 'percentage' => 75],
        ],
    ])->assertSessionHasNoErrors();

    $rule->refresh();
    expect($rule->name)->toBe('Transportes renombrada')
        ->and($rule->lines)->toHaveCount(2)
        ->and($rule->lines->firstWhere('cost_center_id', $ccB->id)->percentage)->toEqual('75.00');
});

it('rechaza actualizar una norma de otra compañía', function () {
    $companyB = Company::factory()->create();
    $ccB = CostCenter::factory()->create(['company_id' => $companyB->id, 'code' => 'A']);
    $ruleB = CostAllocationRule::factory()->withEvenSplit($ccB)->create(['company_id' => $companyB->id, 'code' => 'TRANSP']);

    logInAsCompanyUser();

    $this->put(route('cost-allocation-rules.update', $ruleB->id), [
        'code' => 'TRANSP',
        'name' => 'Intento ajeno',
        'valid_from' => '2026-01-01',
        'lines' => [['cost_center_id' => $ccB->id, 'percentage' => 100]],
    ])->assertNotFound();
});

it('elimina una norma de reparto que nunca generó movimientos', function () {
    ['company' => $company] = logInAsCompanyUser();
    $cc = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'A']);
    $rule = CostAllocationRule::factory()->withEvenSplit($cc)->create(['company_id' => $company->id, 'code' => 'TRANSP']);

    $this->delete(route('cost-allocation-rules.destroy', $rule->id))->assertSessionHasNoErrors();

    expect(CostAllocationRule::find($rule->id))->toBeNull();
});
