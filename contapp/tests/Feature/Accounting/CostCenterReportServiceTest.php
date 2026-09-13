<?php

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRule;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Accounting\Models\FiscalPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Accounting\Services\CostCenterReportService;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;

it('agrupa los movimientos del período por centro de costo, con desglose por cuenta', function () {
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

    $centerA = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-A', 'name' => 'Administración']);
    $centerB = CostCenter::factory()->create(['company_id' => $company->id, 'code' => 'CC-B', 'name' => 'Ventas']);
    $rule = CostAllocationRule::factory()
        ->withEvenSplit($centerA, $centerB)
        ->create(['company_id' => $company->id]);

    app(PostJournalService::class)->post(
        $company, $documentType, new DateTime('2026-02-01'), new DateTime('2026-02-01'),
        [
            new JournalLineInput($expense->id, $company->local_currency_id, debit: '100', credit: 0, costAllocationRuleId: $rule->id),
            new JournalLineInput($cash->id, $company->local_currency_id, debit: 0, credit: '100'),
        ],
        'Gasto repartido entre dos centros'
    );

    $result = app(CostCenterReportService::class)->build($company, '2026-02-01', '2026-02-28');

    expect($result->groups)->toHaveCount(2);

    $groupA = collect($result->groups)->firstWhere('costCenterCode', 'CC-A');
    $groupB = collect($result->groups)->firstWhere('costCenterCode', 'CC-B');

    expect($groupA->totalDebit)->toEqual('50.00')
        ->and($groupA->lines)->toHaveCount(1)
        ->and($groupA->lines[0]->accountCode)->toBe($expense->code)
        ->and($groupB->totalDebit)->toEqual('50.00')
        ->and($result->grandTotalDebit)->toEqual('100.00');
});

it('devuelve un resultado vacío cuando ningún movimiento del período tiene centro de costo asignado', function () {
    $company = Company::factory()->create();

    $result = app(CostCenterReportService::class)->build($company, '2026-02-01', '2026-02-28');

    expect($result->groups)->toBe([])
        ->and($result->grandTotalDebit)->toEqual('0.00');
});
