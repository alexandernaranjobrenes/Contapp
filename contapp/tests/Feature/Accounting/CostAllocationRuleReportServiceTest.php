<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\CostAllocationRuleReportService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

it('detecta una diferencia entre lo definido hoy por la norma y lo que realmente se contabilizó en el período', function () {
    $company = Company::factory()->create();

    ExchangeRate::factory()->create([
        'company_id' => $company->id,
        'currency_id' => $company->foreign_currency_id,
        'rate_date' => '2026-01-01',
        'rate' => '520.000000',
    ]);

    $cash = ChartOfAccount::factory()->create(['company_id' => $company->id, 'code' => '1-01-01-01-001']);
    $expense = ChartOfAccount::factory()->create([
        'company_id' => $company->id, 'code' => '5-01-01-01-001',
        'account_type' => 'expense', 'normal_balance' => 'debit',
    ]);
    $documentType = DocumentType::factory()->create(['company_id' => $company->id]);

    $fiscalYear = FiscalYear::factory()->create(['company_id' => $company->id, 'year' => 2026]);
    FiscalPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id, 'period_number' => 2,
        'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'open',
    ]);

    $centerA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-A']);
    $centerB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-B']);

    $rule = CostAllocationRule::factory()->create(['company_id' => $company->id, 'code' => 'NORMA-01']);
    $rule->lines()->create(['cost_center_id' => $centerA->id, 'percentage' => '70.00', 'position' => 1]);
    $rule->lines()->create(['cost_center_id' => $centerB->id, 'percentage' => '30.00', 'position' => 2]);

    // Se contabiliza CON la norma vigente 70/30 — el reparto real quedará
    // exactamente en 70/30 (CostAllocationSplitter reparte según la norma
    // vigente al momento de contabilizar).
    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-02-01'), new DateTime('2026-02-01'),
        [
            new JournalLineInput($expense->id, $company->local_currency_id, debit: '100', credit: 0, costAllocationRuleId: $rule->id),
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: '100'),
        ],
        'Gasto repartido 70/30'
    );

    // La norma cambia DESPUÉS de contabilizar — el reporte compara contra la
    // definición VIGENTE hoy (50/50), así que ahora debe mostrar una
    // variación de 20 puntos porcentuales contra lo que ya quedó contabilizado.
    $rule->lines()->where('cost_center_id', $centerA->id)->update(['percentage' => '50.00']);
    $rule->lines()->where('cost_center_id', $centerB->id)->update(['percentage' => '50.00']);

    $result = app(CostAllocationRuleReportService::class)->build($company, '2026-02-01', '2026-02-28');

    expect($result->groups)->toHaveCount(1);

    $group = $result->groups[0];
    expect($group->ruleCode)->toBe('NORMA-01')
        ->and($group->totalAmount)->toEqual('100.00');

    $lineA = collect($group->lines)->firstWhere('costCenterCode', $centerA->code);
    $lineB = collect($group->lines)->firstWhere('costCenterCode', $centerB->code);

    expect($lineA->definedPercentage)->toEqual('50.00')
        ->and($lineA->actualAmount)->toEqual('70.00')
        ->and($lineA->actualPercentage)->toEqual('70.00')
        ->and($lineA->variancePercentagePoints)->toEqual('20.00')
        ->and($lineB->definedPercentage)->toEqual('50.00')
        ->and($lineB->actualAmount)->toEqual('30.00')
        ->and($lineB->variancePercentagePoints)->toEqual('-20.00');
});

it('devuelve un resultado vacío cuando ninguna norma de reparto tuvo movimiento en el período', function () {
    $company = Company::factory()->create();

    $result = app(CostAllocationRuleReportService::class)->build($company, '2026-02-01', '2026-02-28');

    expect($result->groups)->toBe([]);
});
